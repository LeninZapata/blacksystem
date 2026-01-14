<?php
interface chatApiProviderInterface {
  function getProviderName(): string;
  function validateConfig(): bool;
  function sendMessage(string $number, string $message, string $url = ''): array;
  function sendPresence(string $number, string $presenceType, int $delay = 1200): array;
  function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array;
}