<?php

declare(strict_types=1);

namespace Drupal\test_jquery_free\Drush\Commands;

use Drupal\test_jquery_free\ReadinessScanner;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the jQuery readiness report in Drush.
 */
#[AsCommand(name: 'test-jquery-free:report', description: 'Reports possible jQuery dependencies in enabled extensions.')]
final class ReadinessReportCommand extends Command {

  public function __construct(
    private readonly ReadinessScanner $scanner,
  ) {
    parent::__construct();
  }

  /**
   * Creates the command after Drush bootstraps the site.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(ReadinessScanner::class));
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'Output format (table or json)', 'table');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $format = $input->getOption('format');
    if (!in_array($format, ['table', 'json'], TRUE)) {
      throw new InvalidArgumentException('The format must be table or json.');
    }

    $rows = $this->scanner->rows();
    if ($format === 'json') {
      $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
    }
    else {
      $table = new Table($output);
      $table->setHeaders(['Extension', 'Library or file', 'Line', 'Reason']);
      foreach ($rows as $row) {
        $table->addRow(array_map(static fn (string|int|null $cell): string => OutputFormatter::escape((string) $cell), array_values($row)));
      }
      $table->render();
    }
    return self::SUCCESS;
  }

}
