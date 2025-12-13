<?php
class scraperController {

  function createJob($data) {
    validation::required($data, ['url', 'selector']);

    // Guardar en tabla del extension
    $jobId = db::table('scraper_jobs')->insert([
      'url' => $data['url'],
      'selector' => $data['selector'],
      'status' => 'pending',
      'created_at' => date('Y-m-d H:i:s')
    ]);

    response::success(['job_id' => $jobId], 'Job created', 201);
  }

  function runJob($id) {
    $job = db::table('scraper_jobs')->find($id);
    if (!$job) response::notFound('Job not found');

    // Usar servicio del extension
    $scraper = new puppeteerService();
    $result = $scraper->scrape($job['url'], $job['selector']);

    // Guardar resultado
    db::table('scraper_results')->insert([
      'job_id' => $id,
      'data' => json_encode($result),
      'created_at' => date('Y-m-d H:i:s')
    ]);

    // Actualizar estado
    db::table('scraper_jobs')
      ->where('id', $id)
      ->update(['status' => 'completed']);

    response::success($result);
  }

  function getResults($id) {
    $results = db::table('scraper_results')
      ->where('job_id', $id)
      ->get();

    response::success($results);
  }
}