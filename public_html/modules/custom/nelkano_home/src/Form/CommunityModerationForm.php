<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\comment\CommentInterface;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\CommunityModeration as Moderation;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
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
    $form['#attached']['library'][] = 'nelkano_home/community_moderation';
    $form['#attributes']['class'][] = 'nk-community-moderation';
    $form['#prefix'] = $this->nelkanoAdminPrefix($module, 'community_moderation', 'nk-redesign-admin');
    $form['#suffix'] = '</section></div></div></div>';
    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h1', '#value' => 'Denuncias de Comunidad'];
    $db = \Drupal::database();
    if ($report === '') {
      $query = $db->select(Moderation::TABLE, 'r')->fields('r');
      $priority = $query->addExpression("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END", 'priority');
      $records = $query->orderBy($priority)->orderBy('created', 'DESC')->orderBy('id', 'DESC')
        ->extend(PagerSelectExtender::class)->limit(40)->execute()->fetchAll(\PDO::FETCH_ASSOC);
      $authors = User::loadMultiple(array_unique(array_column($records, 'owner_uid')));
      $names = $this->displayNames($authors);
      $form['queue'] = ['#type' => 'table', '#header' => ['Contenido denunciado', 'Autor o cuenta', 'Motivo', 'Estado', 'Recibida'],
        '#empty' => 'Todavía no hay denuncias.', '#attributes' => ['class' => ['nk-community-moderation-table']],
        '#prefix' => '<div class="nk-community-moderation-scroll">', '#suffix' => '</div>'];
      foreach ($records as $row) {
        $id = (string) $row['id'];
        $form['queue'][$id] = [
          'target' => Link::fromTextAndUrl(Moderation::KINDS[$row['kind']] . ' #' . $row['target_id'],
            Url::fromRoute('nelkano_home.admin_community_report', ['report' => $id]))->toRenderable(),
          'author' => $this->accountLink((int) $row['owner_uid'], $authors, $names),
          'reason' => ['#plain_text' => Moderation::REASONS[$row['reason']]],
          'status' => ['#plain_text' => Moderation::STATUSES[$row['status']]],
          'date' => ['#plain_text' => $this->date((int) $row['created'])],
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
    $entity = $row['kind'] === 'user' ? User::load((int) $row['target_id']) : Moderation::target($row['kind'], (string) $row['target_id']);
    $post = $entity instanceof CommentInterface ? $entity->getCommentedEntity() : $entity;
    $accounts = User::loadMultiple([(int) $row['owner_uid'], (int) $row['reporter_uid'], (int) $row['moderator_uid']]);
    $names = $this->displayNames($accounts);
    $form['back'] = Link::fromTextAndUrl('Volver a las denuncias', Url::fromRoute('nelkano_home.admin_community_moderation'))->toRenderable();
    $form['summary'] = ['#type' => 'container', '#attributes' => ['class' => ['nk-community-moderation-summary']]];
    $form['summary']['target'] = $this->item('Contenido denunciado',
      ['#plain_text' => Moderation::KINDS[$row['kind']] . ' #' . $row['target_id']]);
    $form['summary']['author'] = $this->item($row['kind'] === 'user' ? 'Cuenta denunciada' : 'Autor',
      $this->accountLink((int) $row['owner_uid'], $accounts, $names));
    $form['summary']['reporter'] = $this->item('Denunciante',
      $this->accountLink((int) $row['reporter_uid'], $accounts, $names));
    $form['summary']['reason'] = $this->item('Motivo', ['#plain_text' => Moderation::REASONS[$row['reason']]]);
    $form['summary']['created'] = $this->item('Recibida', ['#plain_text' => $this->date((int) $row['created'])]);
    $form['summary']['status'] = $this->item('Estado de la denuncia', ['#plain_text' => Moderation::STATUSES[$row['status']]]);
    $form['summary']['visibility'] = $this->item('Estado actual del contenido', ['#plain_text' => $this->visibility($entity, $post)]);
    if ((int) $row['resolved'] > 0) {
      $form['summary']['resolved'] = $this->item('Revisada', ['#plain_text' => $this->date((int) $row['resolved'])]);
      $form['summary']['moderator'] = $this->item('Moderador',
        $this->accountLink((int) $row['moderator_uid'], $accounts, $names));
    }
    if ($post instanceof NodeInterface) {
      $form['summary']['publication'] = $this->item('Publicación', Link::fromTextAndUrl(
        (string) $post->label() . ' (#' . $post->id() . ')', $post->toUrl())->toRenderable());
      if ($entity instanceof CommentInterface && $entity->isPublished()) {
        $form['summary']['comment'] = $this->item('Comentario',
          Link::fromTextAndUrl('Ver comentario en la publicación', $entity->toUrl())->toRenderable());
      }
    }
    if ($entity instanceof NodeInterface || $entity instanceof CommentInterface) {
      $form['preview'] = ['#type' => 'container', '#attributes' => ['class' => ['nk-community-moderation-preview']]];
      $field = $entity instanceof NodeInterface ? 'body' : 'field_community_body';
      $text = $entity->hasField($field) ? trim((string) $entity->get($field)->value) : '';
      $form['preview']['text'] = $this->item('Texto denunciado', ['#plain_text' => $text === '' ? 'Sin texto adjunto.' : $text]);
      if ($entity instanceof NodeInterface && $entity->hasField('field_community_image') && !$entity->get('field_community_image')->isEmpty()) {
        $form['preview']['image'] = $entity->get('field_community_image')->view(['label' => 'hidden', 'type' => 'image']);
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
      $this->messenger()->addStatus(match ($form_state->getValue('decision')) {
        'hidden' => 'Contenido retirado de Comunidad.',
        'suspended' => 'Cuenta suspendida.',
        default => 'Denuncia archivada.',
      });
      $form_state->setRedirect('nelkano_home.admin_community_moderation');
    }
    catch (HttpExceptionInterface $error) {
      $this->messenger()->addError($error->getMessage());
      $form_state->setRebuild();
    }
  }

  private function item(string $title, array $value): array {
    return ['#type' => 'item', '#title' => $title, 'value' => $value];
  }

  private function date(int $date): string {
    return \Drupal::service('date.formatter')->format($date, 'short');
  }

  private function displayNames(array $accounts): array {
    if (!$accounts) {
      return [];
    }
    return \Drupal::database()->select('nelkano_profile', 'p')->fields('p', ['uid', 'display_name'])
      ->condition('uid', array_keys($accounts), 'IN')->execute()->fetchAllKeyed();
  }

  private function accountLink(int $uid, array $accounts, array $names): array {
    $account = $accounts[$uid] ?? NULL;
    if ($uid === 0 || !$account instanceof UserInterface) {
      return ['#plain_text' => $uid > 0 ? "Cuenta no disponible (#$uid)" : 'No consta'];
    }
    $name = trim((string) ($names[$uid] ?? '')) ?: $account->getDisplayName();
    if (strcasecmp($name, (string) $account->getEmail()) === 0) {
      $name = 'Usuario';
    }
    return Link::fromTextAndUrl("$name (#$uid)", $account->toUrl())->toRenderable();
  }

  private function visibility(?EntityInterface $entity, ?EntityInterface $post): string {
    if ($entity instanceof UserInterface) {
      return $entity->isActive() ? 'Cuenta activa' : 'Cuenta suspendida';
    }
    if ($entity instanceof EntityPublishedInterface) {
      if (!$entity->isPublished() || ($post instanceof NodeInterface && !$post->isPublished())) {
        return 'Retirado de Comunidad';
      }
      return 'Publicado';
    }
    return 'Ya no existe';
  }

}
