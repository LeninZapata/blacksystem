<?php

interface MessageProcessorInterface {
  
  public function process(array $messages, array $context): array;
}