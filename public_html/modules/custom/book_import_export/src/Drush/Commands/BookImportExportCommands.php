<?php

namespace Drupal\book_import_export\Drush\Commands;

use Drupal\book_import_export\BookTransfer;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Path;

final class BookImportExportCommands extends DrushCommands {
  public function __construct(private readonly BookTransfer $transfer) { parent::__construct(); }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('book_import_export.transfer'));
  }

  /** Export one Book as portable editorial YAML. */
  #[CLI\Command(name: 'book:export')]
  #[CLI\Argument(name: 'uuid', description: 'UUID of the root page (never a local node ID).')]
  #[CLI\Argument(name: 'file', description: 'Local YAML output path, outside the public docroot.')]
  public function export(string $uuid, string $file): void {
    $file = Path::makeAbsolute($file, $this->getConfig()->get('env.cwd'));
    $this->transfer->write($uuid, $file);
    $this->logger()->success('Book exported: ' . $file);
  }

  /** Import Book content, creating revisions; optionally simulate first. */
  #[CLI\Command(name: 'book:import')]
  #[CLI\Argument(name: 'file', description: 'Versioned YAML content file.')]
  #[CLI\Option(name: 'dry-run', description: 'Validate and show plan without writes.')]
  public function import(string $file, array $options = ['dry-run' => FALSE]): void {
    $file = Path::makeAbsolute($file, $this->getConfig()->get('env.cwd'));
    $plan = $this->transfer->import($this->transfer->read($file), (bool) $options['dry-run']);
    foreach ($plan as $uuid => $action) { $this->io()->writeln($action . ' ' . $uuid); }
    $this->logger()->success($options['dry-run'] ? 'Simulation: no writes.' : 'Import committed. Omitted pages/translations retained.');
  }
}
