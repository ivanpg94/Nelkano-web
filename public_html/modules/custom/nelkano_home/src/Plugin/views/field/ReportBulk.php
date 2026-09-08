<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Plugin\views\field;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nelkano_home\Form\ErrorReportBulkForm;
use Drupal\nelkano_home\Service\ReportBulkUpdater;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\field\UncacheableFieldHandlerTrait;
use Drupal\views\Plugin\views\style\Table;
use Drupal\views\ResultRow;

/** Selection is a normal Views field; Views owns all output and exposed forms. */
#[ViewsField('nelkano_report_bulk')]
final class ReportBulk extends FieldPluginBase implements CacheableDependencyInterface {

  use UncacheableFieldHandlerTrait;

  public function query() {}

  public function clickSortable() { return FALSE; }

  public function access($account) {
    return $account->hasPermission('administer nelkano error reports')
      && $account->hasPermission(ReportBulkUpdater::PERMISSION);
  }

  public function getCacheMaxAge() { return 0; }
  public function getCacheContexts() { return ['user.permissions']; }
  public function getCacheTags() { return []; }

  public function form_element_name() { return 'reports'; }

  public function preRender(&$values) {
    parent::preRender($values);
    if ($this->view->style_plugin instanceof Table) {
      $this->options['element_label_class'] .= ' select-all';
      $this->options['label'] = '';
    }
  }

  public function getValue(ResultRow $row, $field = NULL) {
    return '<!--form-item-reports--' . $row->index . '-->';
  }

  public function viewsForm(&$form, FormStateInterface $form_state) {
    if (!$this->access(\Drupal::currentUser())) {
      unset($form['actions']);
      return;
    }
    $form = ErrorReportBulkForm::create(\Drupal::getContainer())->buildForm($form, $form_state, $this->view);
    $form['#attached']['library'][] = 'core/drupal.tableselect';
  }

  public function viewsFormValidate(&$form, FormStateInterface $form_state) {
    ErrorReportBulkForm::create(\Drupal::getContainer())->validateForm($form, $form_state);
  }

  public function viewsFormSubmit(&$form, FormStateInterface $form_state) {
    ErrorReportBulkForm::create(\Drupal::getContainer())->submitForm($form, $form_state);
  }

}
