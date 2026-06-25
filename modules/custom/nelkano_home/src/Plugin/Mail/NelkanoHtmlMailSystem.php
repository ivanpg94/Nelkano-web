<?php

namespace Drupal\nelkano_home\Plugin\Mail;

use Drupal\Component\Utility\Html;
use Drupal\Core\Mail\MailInterface;

/**
 * Sends Nelkano transactional emails as real HTML.
 *
 * @Mail(
 *   id = "nelkano_html_mail",
 *   label = @Translation("Nelkano HTML mailer")
 * )
 */
final class NelkanoHtmlMailSystem implements MailInterface {

  public function format(array $message): array {
    $message['body'] = implode("\n\n", $message['body']);
    return $message;
  }

  public function mail(array $message): bool {
    $message['headers']['MIME-Version'] = '1.0';
    $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
    $message['headers']['Content-Transfer-Encoding'] = '8bit';

    $smtp_host = trim((string) getenv('NELKANO_SMTP_HOST'));
    if ($smtp_host !== '') {
      return $this->smtpMail($smtp_host, (int) (getenv('NELKANO_SMTP_PORT') ?: 25), $message);
    }

    return $this->phpMail($message);
  }

  private function phpMail(array $message): bool {
    $headers = [];
    foreach ($message['headers'] as $name => $value) {
      if (str_contains($name, "\n") || str_contains($name, "\r")) {
        continue;
      }
      $headers[] = $name . ': ' . str_replace(["\r", "\n"], '', (string) $value);
    }

    $to = str_replace(["\r", "\n"], '', (string) $message['to']);
    $subject = mb_encode_mimeheader((string) $message['subject'], 'UTF-8', 'B');
    return mail($to, $subject, (string) $message['body'], implode("\r\n", $headers));
  }

  private function smtpMail(string $host, int $port, array $message): bool {
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 10);
    if (!$socket) {
      return FALSE;
    }

    stream_set_timeout($socket, 10);
    $from = $this->extractEmail((string) ($message['headers']['Sender'] ?? $message['from'] ?? 'contacto@nelkano.com'));
    $to = $this->extractEmail((string) $message['to']);

    $ok = $this->expect($socket, 220)
      && $this->command($socket, 'EHLO nelkano.local', 250)
      && $this->command($socket, 'MAIL FROM:<' . $from . '>', 250)
      && $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251])
      && $this->command($socket, 'DATA', 354);

    if ($ok) {
      fwrite($socket, $this->smtpPayload($message) . "\r\n.\r\n");
      $ok = $this->expect($socket, 250);
    }

    $this->command($socket, 'QUIT', 221);
    fclose($socket);
    return $ok;
  }

  private function smtpPayload(array $message): string {
    $headers = [
      'To: ' . $message['to'],
      'Subject: ' . mb_encode_mimeheader((string) $message['subject'], 'UTF-8', 'B'),
    ];

    foreach ($message['headers'] as $name => $value) {
      if (str_contains($name, "\n") || str_contains($name, "\r")) {
        continue;
      }
      $headers[] = $name . ': ' . str_replace(["\r", "\n"], '', (string) $value);
    }

    return $this->dotEscape(implode("\r\n", $headers) . "\r\n\r\n" . (string) $message['body']);
  }

  private function command($socket, string $command, int|array $expected): bool {
    fwrite($socket, $command . "\r\n");
    return $this->expect($socket, $expected);
  }

  private function expect($socket, int|array $expected): bool {
    $expected = (array) $expected;
    do {
      $line = fgets($socket, 512);
      if ($line === FALSE || strlen($line) < 3) {
        return FALSE;
      }
      $code = (int) substr($line, 0, 3);
    } while (isset($line[3]) && $line[3] === '-');

    return in_array($code, $expected, TRUE);
  }

  private function extractEmail(string $value): string {
    if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
      return $matches[1];
    }
    return trim($value);
  }

  private function dotEscape(string $payload): string {
    $payload = preg_replace("/\r\n|\r|\n/", "\r\n", $payload) ?? $payload;
    return preg_replace('/^\./m', '..', $payload) ?? $payload;
  }

}
