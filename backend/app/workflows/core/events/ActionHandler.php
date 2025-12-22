<?php

interface ActionHandler {
  
  public function handle($context): array;
  
  public function getActionName(): string;
}