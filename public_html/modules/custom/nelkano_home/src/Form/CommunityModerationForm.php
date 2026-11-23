<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\comment\CommentInterface;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\CommunityModeration as Moderation;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CommunityModerationForm extends FormBase {

  use AdminFormUiTrait;

  public function getFormId(): string {
    return 'nelkano_community_moderation';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $report = ''): array {
    $form['#cache']['max-age'] = 0;
    $module = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    $form['#attached']['library'] = ['nelkano_home/admin_forms', 'nelkano_home/redesign_admin'];
    $form['#attributes']['class'][] = 'nk-admin-config-form';
    $form['#prefix'] = $this->nelkanoAdminPrefix($module, 'community_moderation', 'nk-redesign-admin');
    $form['#suffix'] = '</section></div></div></div>';
    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h1', '#value' => 'Denuncias de Comunidad'];
    $db = \Drupal::database();
    if ($report === '') {
      $query = $db->select(Moderation::TABLE, 'r')->fields('r');
      $priority = $query->addExpression("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END", 'priority');
      $records = $query->orderBy($priority)->orderBy('created', 'DESC')->orderBy('id', 'DESC')
        ->extend(PagerSelectExtender::class)->limit(40)->execute()->fetchAll(\PDO::FETCH_ASSOC);
      // Prioritize pending reports on every page, followed by dated decisions.
      $form['queue'] = ['#type' => 'table', '#header' => ['Denuncia', 'Motivo', 'Estado', 'Fecha', 'Acción'],
        '#empty' => 'Todavía no hay denuncias.'];
      foreach ($records as $row) {
        $id = (string) $row['id'];
        $form['queue'][$id] = [
          'target' => ['#plain_text' => Moderation::KINDS[$row['kind']] . ' #' . $row['target_id']],
          'reason' => ['#plain_text' => Moderation::REASONS[$row['reason']]],
          'status' => ['#plain_text' => Moderation::STATUSES[$row['status']]],
          'date' => ['#plain_text' => \Drupal::service('date.formatter')->format((int) $row['created'], 'short')],
          'review' => Link::fromTextAndUrl('Abrir', Url::fromRoute('nelkano_home.admin_community_report', ['report' => $id]))->toRenderable(),
        ];
      }
      $form['pager'] = ['#type' => 'pager'];
      return $form;
    }
    $row = $db->select(Moderation::TABLE, 'r')->fields('r')->condition('id', (int) $report)->execute()->fetchAssoc();
    if (!$row) {
      throw new NotFoundHttpException();
    }
    $form_state->set('moderation_report', (int) $row['id']);
    $entity = Moderation::target($row['kind'], (string) $row['target_id']);
    $form['back'] = Link::fromTextAndUrl('Volver a las denuncias', Url::fromRoute('nelkano_home.admin_community_moderation'))->toRenderable();
    $form['summary'] = ['#type' => 'item', '#title' => Moderation::KINDS[$row['kind']],
      'value' => ['#plain_text' => ($entity?->label() ?? 'Contenido no disponible') . ' · ' . Moderation::REASONS[$row['reason']] . ' · ' . Moderation::STATUSES[$row['status']]]];
    if ($entity instanceof NodeInterface || $entity instanceof CommentInterface) {
      $field = $entity instanceof NodeInterface ? 'body' : 'field_community_body';
      if ($entity->hasField($field)) {
        $form['content'] = ['#type' => 'item', '#title' => 'Texto denunciado', 'value' => ['#plain_text' => (string) $entity->get($field)->value]];
      }
      if ($entity instanceof NodeInterface && $entity->hasField('field_community_image') && !$entity->get('field_community_image')->isEmpty()) {
        $form['image'] = $entity->get('field_community_image')->view(['label' => 'hidden', 'type' => 'image']);
      }
    }
    if ($row['status'] === 'pending') {
      $actions = ['dismissed' => 'Archivar denuncia', 'suspended' => 'Suspender la cuenta'];
      if ($entity instanceof NodeInterface || $entity instanceof CommentInterface) {
        $actions['hidden'] = 'Retirar el contenido';
      }
      $form['decision'] = ['#type' => 'select', '#title' => 'Decisión', '#empty_option' => 'Selecciona una acción', '#required' => TRUE, '#options' => $actions];
      $form['actions'] = ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => 'Guardar decisión']];
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      Moderation::resolve($this->currentUser(), (int) $form_state->get('moderation_report'), (string) $form_state->getValue('decision'));
      $this->messenger()->addStatus('Decisión guardada.');
      $form_state->setRedirect('nelkano_home.admin_community_moderation');
    }
    catch (HttpExceptionInterface $error) {
      $this->messenger()->addError($error->getMessage());
      $form_state->setRebuild();
    }
  }

}
