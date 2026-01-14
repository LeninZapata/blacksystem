<?php
interface aiProviderInterface {
  public function getProviderName(): string;
  public function chatCompletion($prompt, $options = []): array;
  public function transcribeAudio($audioUrl): array;
  public function analyzeImage($imageDataUri, $instruction): array;
  public function validateConfig(): bool;
}