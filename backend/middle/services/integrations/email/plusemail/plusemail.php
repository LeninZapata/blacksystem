<?php
class plusemail {
  private $apiKey;
  private $baseUrl = 'https://api.plusemail.com/v1';

  function __construct() {
    $this->apiKey = $_ENV['PLUSEMAIL_API_KEY'] ?? null;
  }

  function send($to, $subject, $body, $opts = []) {
    $data = [
      'to' => $to,
      'subject' => $subject,
      'html' => $body,
      'from' => $opts['from'] ?? 'noreply@example.com'
    ];

    return $this->request('/send', $data);
  }

  private function request($endpoint, $data) {
    $ch = curl_init($this->baseUrl . $endpoint);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $this->apiKey
      ],
      CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
      throw new Exception("PlusEmail Error: HTTP $code");
    }

    return json_decode($res, true);
  }
}