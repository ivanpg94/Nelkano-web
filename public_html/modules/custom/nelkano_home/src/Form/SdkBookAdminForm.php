<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/** Edit real Book content through the same revision-aware transfer service. */
final class SdkBookAdminForm extends SdkBookAdminFormBase {
  public function getFormId(): string { return 'nelkano_sdk_book_admin'; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $language = $this->activeAdminLanguage();
    $this->chrome($form, 'SDK de Nelkano', 'Edita el libro, sus capítulos, su contenido y su posición en el índice.');
    $data = $this->currentData();
    $pages = array_column($data['pages'], NULL, 'uuid');
    $new = \Drupal::request()->query->get('new') === '1';
    $uuid = $new ? ($form_state->get('sdk_new_uuid') ?: \Drupal::service('uuid')->generate()) : \Drupal::request()->query->getString('page', self::BOOK_UUID);
    if (!$new && !isset($pages[$uuid])) { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(); }
    $form_state->set('sdk_new_uuid', $new ? $uuid : NULL);
    $page = $pages[$uuid] ?? ['uuid' => $uuid, 'type' => 'sdk_page', 'langcode' => $language,
      'parent' => self::BOOK_UUID, 'weight' => 100, 'translations' => []];
    $node = $new ? NULL : $this->pageNode($uuid);
    if (!$form_state->has('sdk_revision')) { $form_state->set('sdk_revision', $node?->getRevisionId()); }
    $form_state->set('sdk_page_uuid', $uuid);
    $form_state->set('sdk_language', $language);
    $form_state->set('sdk_new', $new);
    $children = [];
    foreach ($pages as $id => $entry) { $children[$entry['parent'] ?? ''][] = $id; }
    foreach ($children as &$ids) { usort($ids, fn($a, $b) => ($pages[$a]['weight'] <=> $pages[$b]['weight']) ?: strcmp($a, $b)); }
    unset($ids);
    $ordered = [];
    $walk = function (string $parent, int $depth) use (&$walk, &$ordered, $children): void {
      foreach ($children[$parent] ?? [] as $id) { $ordered[$id] = $depth; $walk($id, $depth + 1); }
    };
    $walk('', 0);
    $form['chapters'] = ['#type' => 'details', '#title' => 'Libro y capítulos', '#open' => TRUE];
    $form['chapters']['list'] = ['#type' => 'table', '#header' => ['Página', 'Idioma', 'Estado', 'Orden', 'Acciones'], '#rows' => []];
    $parents = [];
    foreach ($ordered as $id => $depth) {
      $entry = $pages[$id];
      $translation = $entry['translations'][$language] ?? $entry['translations'][$entry['langcode']];
      $title = str_repeat('— ', $depth) . $translation['title'];
      if ($id !== $uuid) { $parents[$id] = $title; }
      $edit = Url::fromRoute('nelkano_home.admin_sdk', [], ['query' => ['page' => $id, 'admin_lang' => $language]]);
      $add = Url::fromRoute('nelkano_home.admin_sdk', [], ['query' => ['new' => 1, 'parent' => $id, 'admin_lang' => $language]]);
      $form['chapters']['list']['#rows'][] = [$title, implode(', ', array_keys($entry['translations'])), $translation['status'] ? 'Publicado' : 'Borrador', $entry['weight'],
        ['data' => ['edit' => ['#type' => 'link', '#title' => 'Editar', '#url' => $edit],
          'space' => ['#plain_text' => ' · '], 'add' => ['#type' => 'link', '#title' => 'Añadir subpágina', '#url' => $add]]]];
    }
    $form['chapters']['add'] = ['#type' => 'link', '#title' => 'Añadir capítulo', '#url' => Url::fromRoute('nelkano_home.admin_sdk', [], ['query' => ['new' => 1, 'admin_lang' => $language]]), '#attributes' => ['class' => ['nk-admin-public-link']]];
    $values = $page['translations'][$language] ?? ['title' => '', 'status' => FALSE, 'body' => ['value' => '', 'summary' => '', 'format' => 'basic_html'], 'alias' => ''];
    $form['editor'] = ['#type' => 'details', '#title' => $new ? 'Nueva página' : 'Editar: ' . ($values['title'] ?: 'Nueva traducción'), '#open' => TRUE];
    $form['editor']['title'] = ['#type' => 'textfield', '#title' => 'Título', '#required' => TRUE, '#default_value' => $values['title'], '#maxlength' => 255];
    $form['editor']['body'] = ['#type' => 'text_format', '#title' => 'Contenido', '#default_value' => $values['body']['value'], '#format' => $values['body']['format'], '#rows' => 18, '#description' => 'El libro se exporta como texto y enlaces. Los archivos adjuntos y las im�genes subidas al servidor no se incluyen en el YAML.'];
    $form['editor']['summary'] = ['#type' => 'textarea', '#title' => 'Resumen', '#default_value' => $values['body']['summary'], '#rows' => 2];
    $form['editor']['alias'] = ['#type' => 'textfield', '#title' => 'URL de la página', '#description' => 'Ruta sin prefijo de idioma, por ejemplo /sdk-de-nelkano/mi-capitulo. Vacía para usar la URL de Drupal.', '#default_value' => $values['alias']];
    $form['editor']['published'] = ['#type' => 'checkbox', '#title' => 'Publicado', '#description' => $uuid === self::BOOK_UUID ? 'Al despublicar la portada, el SDK desaparece del menú en este idioma y el libro deja de estar disponible para los visitantes. Puedes seguir editándolo desde la administración.' : 'Las páginas despublicadas no están disponibles para los visitantes ni aparecen en el índice público.', '#default_value' => $values['status']];
    if ($uuid !== self::BOOK_UUID) {
      $parent = $new ? \Drupal::request()->query->getString('parent', self::BOOK_UUID) : $page['parent'];
      $form['editor']['parent'] = ['#type' => 'select', '#title' => 'Página superior', '#options' => $parents, '#default_value' => isset($parents[$parent]) ? $parent : self::BOOK_UUID, '#required' => TRUE];
    }
    $form['editor']['weight'] = ['#type' => 'number', '#title' => 'Orden', '#description' => 'Los números menores aparecen primero entre páginas del mismo nivel.', '#default_value' => $page['weight'], '#min' => -32768, '#max' => 32767, '#required' => TRUE];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => 'Guardar página'];
    return $form;
  }

  private function editedData(FormStateInterface $form_state): array {
    $data = $this->currentData();
    $uuid = $form_state->get('sdk_page_uuid');
    $lang = $form_state->get('sdk_language');
    $node = $this->pageNode($uuid);
    if (!$form_state->get('sdk_new') && (string) $node?->getRevisionId() !== (string) $form_state->get('sdk_revision')) {
      throw new \InvalidArgumentException('La página cambió mientras editabas. Recarga y revisa los cambios antes de guardar.');
    }
    $index = array_search($uuid, array_column($data['pages'], 'uuid'), TRUE);
    if ($index === FALSE) { $index = count($data['pages']); $data['pages'][$index] = ['uuid' => $uuid, 'type' => 'sdk_page', 'langcode' => $lang, 'parent' => self::BOOK_UUID, 'weight' => 0, 'translations' => []]; }
    $page = &$data['pages'][$index];
    $page['weight'] = (int) $form_state->getValue('weight');
    if ($uuid !== self::BOOK_UUID) { $page['parent'] = $form_state->getValue('parent'); }
    $body = $form_state->getValue('body');
    $page['translations'][$lang] = ['title' => trim($form_state->getValue('title')), 'status' => (bool) $form_state->getValue('published'),
      'body' => ['value' => $body['value'], 'summary' => $form_state->getValue('summary'), 'format' => $body['format']], 'alias' => trim($form_state->getValue('alias'))];
    return $data;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try { $data = $this->editedData($form_state); $this->transfer()->import($data, TRUE); }
    catch (\InvalidArgumentException | \RuntimeException $e) { $form_state->setErrorByName('editor', $e->getMessage()); }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->transfer()->import($this->editedData($form_state));
      $this->messenger()->addStatus('Página guardada. Los cambios crean una revisión y actualizan el índice.');
      $form_state->setRedirect('nelkano_home.admin_sdk', [], ['query' => ['page' => $form_state->get('sdk_page_uuid'), 'admin_lang' => $form_state->get('sdk_language')]]);
    }
    catch (\InvalidArgumentException | \RuntimeException $e) { $this->messenger()->addError($e->getMessage()); $form_state->setRebuild(); }
  }
}
