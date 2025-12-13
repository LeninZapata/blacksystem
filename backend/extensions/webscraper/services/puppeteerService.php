<?php
class puppeteerService {
  
  function scrape($url, $selector) {
    // Ejecutar Puppeteer (Node.js)
    $command = "node " . __DIR__ . "/../scripts/scraper.js " 
             . escapeshellarg($url) . " " 
             . escapeshellarg($selector);
    
    $output = shell_exec($command);
    return json_decode($output, true);
  }

  function getContent($url) {
    $command = "node " . __DIR__ . "/../scripts/getContent.js " 
             . escapeshellarg($url);
    
    return shell_exec($command);
  }

  function screenshot($url, $path) {
    $command = "node " . __DIR__ . "/../scripts/screenshot.js " 
             . escapeshellarg($url) . " " 
             . escapeshellarg($path);
    
    exec($command);
    return $path;
  }
}