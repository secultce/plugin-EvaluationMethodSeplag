<?php 

namespace EvaluationMethodSeplag\Controllers;

require __DIR__ . './../vendor/autoload.php';

use Exception;
use MapasCulturais\App;
use MapasCulturais\i;
use GuzzleHttp\Client;
use MapasCulturais\Entities\RegistrationEvaluation;
use stdClass;

class Evaluate extends \MapasCulturais\Controller {
  
  public function POST_run() {
    set_time_limit(-1);
    
    $app = App::i();
    
    $this->config = $app->plugins['EvaluationMethodSeplag']->config;

    $this->auth();
        
    if (!isset($this->token)) {
      // Isso está feio, arrumar depois.
      echo 'Erro ao se autenticar com a SEPLAG!. Informe aos desenvolvedores.';
      return;
    }

    $user = $app->repo("User")->find($this->config["user_id"]);
    $opportunity = $app->repo("Opportunity")->find($this->config["opportunity_id"]);

    $sql = "
      SELECT
        reg.id, 
        REPLACE(REPLACE(REPLACE(REPLACE(reg_me.value, '.', '' ),'/',''),'-',''), '\"', '') AS value,
        reg_ev.id AS exists
      FROM 
        registration reg 
        JOIN registration_meta reg_me ON reg_me.object_id = reg.id AND reg_me.key = 'field_26519'
        LEFT JOIN registration_evaluation reg_ev ON reg_ev.registration_id = reg.id
      WHERE
        reg.status = 1
        AND reg.opportunity_id = {$this->config['opportunity_id']}
      ";


    if ($this->data["areReassessed"] === "1" && $this->data["formEvaluation"] === "all") {
      // Reavaliadas (SIM) && Avaliar (TODOS)
    }

    if ($this->data["areReassessed"] === "1" && $this->data["formEvaluation"] === "selected") {
      // Reavaliadas (SIM) && Avaliar (SELECIONADOS)
      // Vou ter que pegar os que já estão em registration_evaluation e fazer o merge
      $registrations_array = explode(";", $this->data["listSelected"]);
      $registrations_string = implode(", ", $registrations_array); 
      
      $sql .= "AND reg.id IN ($registrations_string)";
    }

    if ($this->data["areReassessed"] === "0" && $this->data["formEvaluation"] === "all") {
      // Reavaliadas (NÃO) && Avaliar (TODOS)
      $sql .= "AND reg_ev.id IS NULL";
    }
    
    if ($this->data["areReassessed"] === "0" && $this->data["formEvaluation"] === "selected") {
      // Reavaliadas (NÃO) && Avaliar (SELECIONADOS)
      $registrations_array = explode(";", $this->data["listSelected"]);
      $registrations_string = implode(", ", $registrations_array); 

      $sql .= "AND reg_ev.id IS NULL AND reg.id IN ($registrations_string)";

      // Meter um NOT IN aqui...
    }

    $sql .= ";";

    $stmt = $app->em->getConnection()->prepare($sql);
    $stmt->execute();
    $data = $stmt->fetchAll();

    foreach($data as $d) {
      $response = null;
  
      try {
        $response = $this->search($d["value"]);
      } catch (\Exception $e) {
        continue;
        $app->log->error("Erro de busca na API da Seplag. Inscrição ID {$d['id']}");
      }
      

      $result = !isset($response) ? 10: 2;
      $now = date('d/mY H:i:s');
      $evaluation_data_obs = !isset($response) ? $now: "$now | Descumpriu o DECRETO Nº33.953, de 25 de fevereiro de 2021. ART.3 INCISO IV - Não exercerem, a qualquer título, cargo, emprego ou função pública em quaisquer das esferas de governo";
      
      $registration = $app->repo("Registration")->find($d["id"]);

      $evaluation = new RegistrationEvaluation();

      $evaluation->id = $d["exists"];
      $evaluation->registration = $registration;
      $evaluation->user = $user;
      $evaluation->result = $result;

      $evaluation->evaluationData = [
        "status" => $result,
        "obs" => $evaluation_data_obs
      ];

      $evaluation->status = 1;

      $app->em->persist($evaluation);
      $app->em->flush();

      $app->log->info("Avaliação realizada com sucesso! Inscrição ID {$d['id']}");
    } 

    $app->redirect($app->createUrl('opportunity', 'single', [ $this->config["opportunity_id"] ]));
  }

 

  /**
   * 
   */
  private function auth() {
    $client = new Client();
    
    $api = $this->config['api_seplag']['auth'];

    $bodyJson = json_encode([
      'cpf' => $api["keys"]["cpf"],
      'password' => $api["keys"]["password"],
      'idSistema' => $api["keys"]["idSistema"]
    ]);

    try {
      $response = $client->post($api['URL'], [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body'    => $bodyJson
      ]);
    } catch (\Exception $e) {
      $this->token = null;
      return;
    }

    $response = json_decode($response->getBody(), true);

    if (isset($response) && $response['sucesso']) {
      $this->token = $response['token'];
    }
  }

  private function search($cpf) {
    $client = new Client([
      'verify' => false
    ]);

    $api = $this->config['api_seplag']['search'];

    try {
      $response = $client->request($api['method'], "{$api['URL']}?numeroDocumento=$cpf", [
        'headers' => [
          'Content-Type' => 'application/json', 
          'Accept' => 'application/json',
          'Authorization' => "Bearer {$this->token}"
        ]
      ]);
    } catch (\Exception $e) {
      throw $e;
    }

    return json_decode($response->getBody(), true);
  }
}