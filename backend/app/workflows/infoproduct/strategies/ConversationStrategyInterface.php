<?php

interface ConversationStrategyInterface {
  public function execute(array $context): array;  // ← Con type hints
}