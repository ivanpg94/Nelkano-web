<?php

declare(strict_types=1);

namespace Drupal\nelkano_modules\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;

/** Keep the module catalogue's public shell independent of editorial access. */
final class ModulePageHooks {

  #[Hook('page_top', order: new OrderAfter(modules: ['toolbar']))]
  public function pageTop(array &$page_top): void {
    if (nelkano_modules_is_page()) {
      unset($page_top['toolbar']);
    }
  }

  #[Hook('preprocess_html', order: new OrderAfter(modules: ['toolbar']))]
  public function preprocessHtml(array &$variables): void {
    if (nelkano_modules_is_page()) {
      $variables['attributes']['class'][] = 'nm-catalog';
      if ($variables['attributes'] instanceof \Drupal\Core\Template\Attribute) {
        $variables['attributes']->removeClass('toolbar-loading');
      }
      else {
        $variables['attributes']['class'] = array_values(array_diff($variables['attributes']['class'] ?? [], ['toolbar-loading']));
      }
    }
  }

}
