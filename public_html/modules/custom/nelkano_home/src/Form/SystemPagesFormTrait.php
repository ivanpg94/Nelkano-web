<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\SystemPages;

/** Fields and persistence for the dedicated systems settings form. */
trait SystemPagesFormTrait {

  private const SYSTEM_FIELDS = [
    'status_note' => ['Nota de estado (básico / experimental)', 'Status notice (basic / experimental)', 'textarea'],
    'related_ids' => ['Sistemas relacionados', 'Related systems', 'textarea'],
    'card_title' => ['Nombre en la home', 'Home card name'],
    'title' => ['Título de la página', 'Page title'],
    'short_name' => ['Nombre corto', 'Short name'],
    'summary' => ['Descripción de la tarjeta', 'Card description', 'textarea'],
    'tagline' => ['Presentación bajo el título', 'Introduction below the title', 'textarea'],
    'manufacturer' => ['Fabricante / autor', 'Manufacturer / author'],
    'year' => ['Año', 'Year'],
    'formats' => ['Formatos (uno por línea)', 'Formats (one per line)', 'textarea'],
    'overview' => ['Descripción del sistema', 'About this system', 'textarea'],
    'features' => ['Qué puedes hacer (un punto por línea)', 'Features (one per line)', 'textarea'],
    'limitations' => ['Limitaciones (un punto por línea)', 'Limitations (one per line)', 'textarea'],
  ];

  private function buildSystemPages(array &$form, string $language, FormStateInterface $form_state): void {
    $data = SystemPages::content($language);
    $section = ['#type' => 'container', '#tree' => TRUE];
    $section['labels'] = ['#type' => 'details', '#title' => $language === 'es' ? 'Textos comunes y botones' : 'Shared headings and buttons'];
    foreach ($data['labels'] as $key => $value) {
      if (in_array($key, ['status','download'], TRUE)) {continue;}
      $section['labels'][$key] = ['#type' => str_contains($key, 'description') ? 'textarea' : 'textfield', '#title' => $data['labels'][$key], '#default_value' => $value, '#maxlength' => 2048, '#rows' => 2, '#required' => TRUE];
    }
    $label_groups = [
      'navigation'=>['title'=>$language==='es'?'Navegación y botones':'Navigation and buttons','fields'=>['home','systems','all_systems','more','skip','back']],
      'index'=>['title'=>$language==='es'?'Listado de sistemas':'Systems list','fields'=>['index_eyebrow','index_title','all','filter','index_description','empty']],
      'headings'=>['title'=>$language==='es'?'Títulos de las fichas':'System page headings','fields'=>['about','features','limitations','steps','faq','facts','related','compatibility','compatibility_description']],
      'facts'=>['title'=>$language==='es'?'Etiquetas de la ficha':'System information labels','fields'=>['manufacturer','year','category','formats']],
    ];
    foreach (['basico','experimental','jugable','estable','verificado'] as $tier) {
      $name = $data['labels']['tier_'.$tier];
      $section['labels']['tier_'.$tier]['#title']=$language==='es'?'Nombre del nivel':'Level name';
      $section['labels']['tier_'.$tier.'_description']['#title']=$language==='es'?'Descripción del nivel':'Level description';
      $label_groups['tier_'.$tier]=['title'=>$name,'class'=>'r-paired-grid','fields'=>['tier_'.$tier,'tier_'.$tier.'_description']];
    }
    foreach (['index_eyebrow'=>'Etiqueta del listado','index_title'=>'Título del listado','index_description'=>'Descripción del listado','empty'=>'Mensaje sin resultados','compatibility_description'=>'Descripción del enlace de compatibilidad'] as $field=>$label) {
      if ($language==='es') {$section['labels'][$field]['#title']=$label;}
    }
    $this->groupAdminFields($section['labels'],$label_groups,[$language,'systems','labels']);
    $selected=\Drupal::request()->query->getString('system');
    if (!isset($data['pages'][$selected])) { $selected=array_key_first($data['pages']); }
    $section['picker']=['#type'=>'container','#attributes'=>['class'=>['r-system-picker'],'data-r-server-picker'=>'true']];
    foreach($data['pages'] as $id=>$page) {
      $section['picker'][$id]=['#type'=>'link','#title'=>($page['short_name']?:$page['card_title']).(!$page['enabled']?($language==='es'?' · Oculto':' · Hidden'):''),'#url'=>Url::fromRoute('nelkano_home.admin_systems',[],['query'=>['system'=>$id,'admin_lang'=>$language]]),'#attributes'=>['aria-current'=>$id===$selected?'true':'false']];
    }
    $section['pages'] = ['#type' => 'container'];
    foreach ($data['pages'] as $id => $page) {
      if ($id !== $selected) { continue; }
      $section['pages'][$id] = ['#type' => 'details', '#title' => $page['card_title'] ?: $id, '#open' => TRUE, '#attributes' => ['data-r-system' => $id]];
      $entry = &$section['pages'][$id];
      $entry['publication']=['#type'=>'container','#attributes'=>['class'=>['r-publication-bar']]];
      $entry['publication']['enabled']=['#type'=>'checkbox','#title'=>$language==='es'?'Visible':'Visible','#default_value'=>$page['enabled'],'#parents'=>[$language,'systems','pages',$id,'enabled'],'#description'=>$language==='es'?'Se aplica a español e inglés. Guarda los cambios para actualizar la visibilidad.':'Applies to Spanish and English. Save changes to update visibility.'];
      $entry['compatibility'] = ['#type' => 'container', '#attributes' => ['class' => ['nk-system-csv']]];
      $entry['compatibility']['csv'] = [
        '#type' => 'file',
        '#title' => $language === 'es' ? 'Compatibilidad (CSV)' : 'Compatibility (CSV)',
        '#attributes' => [
          'accept' => '.csv,text/csv',
          'data-nk-csv-upload' => Url::fromRoute('nelkano_home.upload_compatibility', ['system' => $id])->toString(),
          'data-csrf-token' => \Drupal::csrfToken()->get(\Drupal\Core\Access\CsrfRequestHeaderAccessCheck::TOKEN_KEY),
          'data-language' => $language,
        ],
      ];
      $entry['compatibility']['status'] = ['#type' => 'container', '#attributes' => ['class' => ['nk-system-csv-status'], 'role' => 'status', 'aria-live' => 'polite']];
      $entry['preview'] = ['#type' => 'link', '#title' => $language === 'es' ? 'Ver página' : 'View page', '#url' => Url::fromUserInput(SystemPages::path($id, $language)), '#attributes' => ['target' => '_blank', 'rel' => 'noopener']];
      $entry['show_on_home'] = ['#type' => 'checkbox', '#title' => $language === 'es' ? 'Mostrar tarjeta en Estado actual' : 'Show card in current status', '#default_value' => $page['show_on_home']];
      foreach (self::SYSTEM_FIELDS as $key => $definition) {
        $entry[$key] = ['#type' => $definition[2] ?? 'textfield', '#title' => $definition[$language === 'es' ? 0 : 1], '#default_value' => $page[$key] ?? '', '#required' => in_array($key, ['card_title', 'title'], TRUE)];
        if (($definition[2] ?? '') === 'textarea') {
          $entry[$key]['#rows'] = 3;
        }
        else {
          $entry[$key]['#maxlength'] = 255;
        }
      }
      $entry['related_ids']['#type']='select';
      $entry['related_ids']['#multiple']=TRUE;
      $entry['related_ids']['#options']=array_map(static fn($p)=>$p['card_title'],$data['pages']);
      unset($entry['related_ids']['#options'][$id]);
      $entry['related_ids']['#default_value']=SystemPages::lines($page['related_ids']??'');
      $entry['tier'] = ['#type'=>'select','#title'=>$language==='es'?'Categoría de compatibilidad':'Compatibility category','#options'=>array_map(static fn($c)=>$c['label'], \Drupal\nelkano_home\Service\RedesignContent::categories($language)), '#default_value'=>$page['tier']];
      $entry['image_uri'] = ['#type'=>'managed_file','#title'=>$language==='es'?'Imagen personalizada':'Custom image','#default_value'=>$this->configRowsManagedFileDefault($page['image_uri']??''),'#upload_location'=>'public://nelkano-images','#upload_validators'=>['FileExtension'=>['extensions'=>'png jpg jpeg webp gif'],'FileIsImage'=>[]]];
      $image = \Drupal\nelkano_home\Service\RedesignContent::image($page['image_uri']??'', \Drupal\nelkano_home\Service\RedesignContent::illustration($id));
      $entry['image_preview']=['#theme'=>'image','#uri'=>$image,'#alt'=>'','#width'=>96,'#height'=>96,'#attributes'=>['class'=>['r-admin-preview']]];
      $decorated=\Drupal\nelkano_home\Service\RedesignContent::decorate($id,$page,$language);
      foreach (self::repeatableSystemFields() as $key=>$definition) {
        $entry[$key]=$this->buildConfigRowsElement($decorated[$key],$definition,[$language,'systems','pages',$id,$key],$language,$form_state);
        $entry[$key]['#parents']=[$language,'systems','pages',$id,$key];
      }
      // Explicit parents keep submitted data identical across presentation groups.
      $groups = [
        'identity'=>['title'=>'Identificación y estado','tab'=>'textos','fields'=>['preview','show_on_home','card_title','short_name','title','tier','manufacturer','year','formats','related_ids','status_note']],
        'presentation'=>['title'=>'Textos de presentación','tab'=>'textos','fields'=>['tagline','summary','overview']],
        'features_group'=>['title'=>'Qué puedes hacer hoy','tab'=>'textos','fields'=>['features']],
        'limits_group'=>['title'=>'Limitaciones conocidas','tab'=>'textos','fields'=>['limitations']],
        'steps_group'=>['title'=>'Cómo empezar','tab'=>'textos','fields'=>['steps']],
        'faq_group'=>['title'=>'Preguntas frecuentes','tab'=>'textos','fields'=>['faq']],
        'image'=>['title'=>'Imagen del sistema','tab'=>'imagen','fields'=>['image_preview','image_uri']],
        'csv'=>['title'=>'Compatibilidad por juegos','tab'=>'compatibilidad','fields'=>['compatibility']],
      ];
      foreach ($groups as $group=>$definition) {
        $entry[$group]=['#type'=>'details','#title'=>$definition['title'],'#open'=>FALSE,'#attributes'=>['data-r-tab'=>$definition['tab'],'data-r-group'=>$group]];
        foreach ($definition['fields'] as $field) {
          $entry[$group][$field]=$entry[$field];
          $entry[$group][$field]['#parents']=[$language,'systems','pages',$id,$field];
          unset($entry[$field]);
        }
        if ($group !== 'csv') {
          $entry[$group]['save'] = ['#type'=>'submit','#value'=>$language==='es'?'Guardar sección':'Save section','#name'=>'save_'.$id.'_'.$group,'#r_system'=>$id,'#r_fields'=>$group==='identity'?array_merge($definition['fields'],['enabled']):$definition['fields'],'#attributes'=>['class'=>['r-save-section']]];
        }
      }
      unset($entry);
    }
    $form[$language]['systems'] = $section;
  }

  private function saveSystemPages(FormStateInterface $form_state, string $language): void {
    $data = SystemPages::content($language);
    $values = $form_state->getValue([$language, 'systems']);
    $trigger=$form_state->getTriggeringElement();
    foreach ($data['labels'] as $key => $value) {
      if (!isset($trigger['#r_system']) && isset($values['labels'][$key])) { $data['labels'][$key] = trim((string) $values['labels'][$key]); }
    }
    $trigger=$form_state->getTriggeringElement();
    $selected_fields=$trigger['#r_fields']??NULL;
    $visibility_changes = [];
    foreach ($data['pages'] as $id => &$page) {
      if (!isset($values['pages'][$id]) || (isset($trigger['#r_system']) && $trigger['#r_system'] !== $id)) { continue; }
      $previous=$page;
      foreach (array_keys(self::SYSTEM_FIELDS) as $key) {
        $page[$key] = $key==='related_ids' ? implode("\n",(array)($values['pages'][$id][$key]??[])) : trim((string) $values['pages'][$id][$key]);
      }
      $page['tier'] = (string) $values['pages'][$id]['tier'];
      $page['image_uri'] = $this->normalizeConfigRowsColumnValue($values['pages'][$id]['image_uri']??[], ['type'=>'managed_file']);
      foreach (self::repeatableSystemFields() as $key=>$definition) {
        $page[$key]=$this->normalizeConfigRowsValue($values['pages'][$id][$key]??[],$definition);
      }
      $page['enabled'] = (bool) $values['pages'][$id]['enabled'];
      $page['show_on_home'] = (bool) $values['pages'][$id]['show_on_home'];
      if ($selected_fields !== NULL) { $page=array_replace($previous,array_intersect_key($page,array_flip($selected_fields))); }
      if ($selected_fields === NULL || in_array('enabled', $selected_fields, TRUE)) {$visibility_changes[$id]=$page['enabled'];}
    }
    unset($page);
    $config=$this->configFactory->getEditable(SystemPages::CONFIG)->set($language, $data);
    $other=$language==='es'?'en':'es';
    foreach ($visibility_changes as $id=>$visible) {
      if (is_array($config->get($other.'.pages.'.$id))) {$config->set($other.'.pages.'.$id.'.enabled',$visible);}
    }
    $config->save();
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['config:nelkano_home.settings', 'config:' . SystemPages::CONFIG]);
  }
  private static function repeatableSystemFields(): array {
    return [
      'steps'=>['layout'=>'cards','title'=>'Pasos','columns'=>['title'=>['title'=>'Título'],'description'=>['title'=>'Explicación','type'=>'textarea']]],
      'faq'=>['layout'=>'cards','title'=>'Preguntas','columns'=>['question'=>['title'=>'Pregunta'],'answer'=>['title'=>'Respuesta','type'=>'textarea']]],
    ];
  }
}
