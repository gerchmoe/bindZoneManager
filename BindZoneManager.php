<?php


  class BindZoneManager{

    var $e = false;
    var $error;

    var $domainFile; // Path to the bind zone file

    var $soa = []; // Will contain all data from SOA record

    var $nameservers = []; // Will contain data about the nameservers
    var $markerNSStart; // Starting marker for the nameservers
    var $markerNSEnd; // End marker for the usual nameservers

    var $records = []; // Will contain all other records that won't be able to break the zone
    var $markerRecordsStart; // Starting marker for the usual records
    var $markerRecordsEnd; // End marker for the usual records

    var $fileContents; // Will contain raw file contents

    function __construct(){
      $this->domainFile = 'aurora-pay.space.db';

      $this->markerRecordsStart = '; ----- BindPHP Records Start -----';
      $this->markerRecordsEnd = '; ----- BindPHP Records End -----';

      $this->markerNSStart = '; ----- BindPHP Nameservers Start -----';
      $this->markerNSEnd = '; ----- BindPHP Nameservers End -----';

      $this->Read();
    }

    function Read(){
      // NOTE: We can and should add lock, so file won't be changed from two editors at the same time
      if(file_exists($this->domainFile)){
        $file = file_get_contents($this->domainFile);
        $this->fileContents = $file;

        $this->soa = $this->ReadSOA();
        $this->nameservers = $this->ReadNS();
        $this->records = $this->ReadRecords();

      }
    }
    function ReadSOA(){
      $file = $this->fileContents;

      preg_match("/[\n](.*)( SOA )(.|\n)+[)]/", $file, $matches);

      $soaRaw = $matches[0];
      $soaRaw = preg_replace("/^[\n]+/", '', $soaRaw); // Remove phantom line at the beginning
      $soaRaw = preg_replace("/(;)(.*)[\n]/", '', $soaRaw); // Remove comments for the values in brackets
      $soaRaw = preg_replace("/( )+/", ' ', $soaRaw); // Strip multiple spaces into one
      $soaRaw = preg_replace("/[\n]+/", '', $soaRaw); // Remove all newlines for easier processing
      $soaRaw = preg_replace("/( )(\)|\()/", '', $soaRaw); // Remove brackets to make explode easier

      // echo $soaRaw;

      $values = explode(' ', $soaRaw);

      // print_r($values);

      // variables to retrieve from file

      $soa = [];

      $soa['domain'] = $values[0];
      $soa['ttl'] = $values[1];
      $soa['primaryNS'] = $values[4];
      $soa['mail'] = $values[5];
      $soa['serial'] = $values[6];
      $soa['refresh'] = $values[7];
      $soa['retry'] = $values[8];
      $soa['expire'] = $values[9];
      $soa['minimum'] = $values[10];

      // print_r($soa);

      return $soa;

    }
    function ReadNS(){

      $file = $this->fileContents;

      // Reading NS records
      if(strpos($file, $this->markerNSStart) and strpos($file, $this->markerNSEnd)){

        $area = explode($this->markerNSStart, $file)[1];
        $area = explode($this->markerNSEnd, $area)[0];

        $area = preg_replace("/[\n|\r\n]+/", "\n", $area);
        $area = preg_replace("/^[\n]/", '', $area);
        $area = preg_replace("/[\n]$/", '', $area);
        $area = preg_replace("/[ ]+/", " ", $area);

        $nsLines = explode("\n", $area);

        $nameservers = [];
        foreach($nsLines as $line){

          $items = explode(' ', $line);

          $domain = $items[0];
          $ttl = $items[1];
          $address = $items[4];

          array_push($nameservers, [
            'domain' => $domain,
            'ttl' => $ttl,
            'address' => $address
          ]);

        }

        return $nameservers;

      }
    }
    function ReadRecords(){

      $file = $this->fileContents;

      // Reading usual records
      if(strpos($file, $this->markerRecordsStart) and strpos($file, $this->markerRecordsEnd)){

        $area = explode($this->markerRecordsStart, $file)[1];
        $area = explode($this->markerRecordsEnd, $area)[0];

        if(!empty(preg_replace("/[\n]/", '', $area))){

          $area = preg_replace("/[\n|\r\n]+/", "\n", $area);
          $area = preg_replace("/[ ]+/", " ", $area);
          $area = preg_replace("/^[\n]/", '', $area);
          $area = preg_replace("/[\n]$/", '', $area);

          $lines = explode("\n", $area);

          // print_r($lines);

          foreach($lines as $i => $line){

            if(preg_match("/^[; ]/", $line)){
              $records[$i]['enabled'] = false;
              $line = preg_replace("/^[;][ ]/", '', $line);
            }else{
              $records[$i]['enabled'] = true;
            }

            // echo $line.PHP_EOL;

            $lineArray = explode(' ', $line);

            $records[$i]['domain'] = $lineArray[0];
            $records[$i]['ttl'] = $lineArray[1];
            $records[$i]['type'] = $lineArray[3];
            $records[$i]['address'] = $lineArray[4];

            if(preg_match("/(;RID_)[0-9a-zA-Z-_.]+$/", $line)){
              $records[$i]['id'] = explode(';RID_', $line)[1];
            }else{
              $records[$i]['id'] = null;
            }

          }

        }else{
          $records = [];
        }


        return $records;

      }else{
        return false;
      }
    }

    function Render(){

      $file = $this->fileContents;

      $updated = $this->RenderSOA($file);
      $updated = $this->RenderNS($updated);
      $updated = $this->RenderRecords($updated);

      return $updated;

    }
    function RenderSOA($file){

      preg_match("/[\n](.*)( SOA )(.|\n)+[)]/", $file, $matches);

      $oldRawSOA = $matches[0];
      $oldRawSOA = preg_replace("/^[\n]+/", '', $oldRawSOA); // Remove phantom line at the beginning

      // select soa section
      // replace old vars to new ones - yikes.

      $domain = $this->soa['domain'];
      $ttl = $this->soa['ttl'];
      $primaryNS = $this->soa['primaryNS'];
      $mail = $this->soa['mail'];
      $serial = $this->soa['serial'];
      $refresh = $this->soa['refresh'];
      $retry = $this->soa['retry'];
      $expire = $this->soa['expire'];
      $minimum = $this->soa['minimum'];

      $newRawSOA = "$domain $ttl IN SOA $primaryNS $mail (\n $serial ;Serial\n $refresh ;Refresh\n $retry ;Retry\n $expire ;Expire\n $minimum ;Minimum\n)";

      // echo $newRawSOA;
      $updated = str_replace($oldRawSOA, $newRawSOA, $file);

      return $updated;

    }
    function RenderNS($file){

      $updated = "";

      foreach($this->nameservers as $ns){

        $domain = $ns['domain'];
        $ttl = $ns['ttl'];
        $address = $ns['address'];

        $updated .= "$domain $ttl IN NS $address\n";

      }

      $updatedFile = preg_replace(
        "/($this->markerNSStart)(.|\n|\r\n)+($this->markerNSEnd)/",
        "$this->markerNSStart\n$updated$this->markerNSEnd", $file);

      return $updatedFile;
    }
    function RenderRecords($file){

      $updated = '';

      foreach($this->records as $record){
        if(!$record['enabled']) $updated .= '; ';

        $domain = $record['domain'];
        $ttl = $record['ttl'];
        $type = $record['type'];
        $address = $record['address'];
        $id = $record['id'];

        $updated .= "$domain $ttl IN $type $address";
        if(!empty($id)){
          $updated .= " ;RID_$id\n";
        }else{
          $updated .= "\n";
        }
      }

      $updated = preg_replace("/[\n]$/", '', $updated);

      $updatedFull = preg_replace(
        "/($this->markerRecordsStart)(.|\n|\r\n)+($this->markerRecordsEnd)/",
        "$this->markerRecordsStart\n$updated\n$this->markerRecordsEnd", $file);

      return $updatedFull;
    }

    function Add($record, $autosave = false){

      array_push($this->records, $record);

      if($autosave) $this->Save();

    }

    function UpdateSOA(array $newParams){
      if(isset($newParams['domain'])){
        $this->soa['domain'] = $newParams['domain'];
      }
      if(isset($newParams['ttl']) and preg_match("/^[0-9]+$/", $newParams['ttl'])){
        $this->soa['ttl'] = $newParams['ttl'];
      }
      if(isset($newParams['primaryNS'])){
        $this->soa['primaryNS'] = $newParams['primaryNS'];
      }
      if(isset($newParams['mail'])){
        $this->soa['mail'] = $newParams['mail'];
      }
      if(isset($newParams['serial']) and preg_match("/^[0-9]+$/", $newParams['serial'])){
        $this->soa['serial'] = $newParams['serial'];
      }
      if(isset($newParams['refresh'])){
        $this->soa['refresh'] = $newParams['refresh'];
      }
      if(isset($newParams['retry'])){
        $this->soa['retry'] = $newParams['retry'];
      }
      if(isset($newParams['expire'])){
        $this->soa['expire'] = $newParams['expire'];
      }
      if(isset($newParams['minimum'])){
        $this->soa['minimum'] = $newParams['minimum'];
      }
    }

    // function ValidateNameserver(){}
    function UpdateNameserver(int $i, array $newParams){
      if(isset($newParams['domain'])){
        $this->nameservers[$i]['domain'] = $newParams['domain'];
      }
      if(isset($newParams['ttl']) and preg_match("/^[0-9]+$/", $newParams['ttl'])){
        $this->nameservers[$i]['ttl'] = $newParams['ttl'];
      }
      if(isset($newParams['address'])){
        $this->nameservers[$i]['address'] = $newParams['address'];
      }
    }
    function UpdateNameservers(array $newNameservers){
      foreach($newNameservers as $i => $newParams){
        if(isset($newParams['domain'])){
          $this->nameservers[$i]['domain'] = $newParams['domain'];
        }
        if(isset($newParams['ttl']) and preg_match("/^[0-9]+$/", $newParams['ttl'])){
          $this->nameservers[$i]['ttl'] = $newParams['ttl'];
        }
        if(isset($newParams['address'])){
          $this->nameservers[$i]['address'] = $newParams['address'];
        }
      }
    }

    function Save($forceUpdateSerial = false){

      if($forceUpdateSerial or $this->Render() !== $this->fileContents){
        $this->soa['serial']++;
      }

      // $this->soa['expire'] = '';

      $jsonSaved = $this->SaveToJson();
      $fileSaved = $this->SaveToFile();

      return $jsonSaved and $fileSaved ? true : false;


    }
    private function SaveToFile(){

      $newContent = $this->Render();
      $save = file_put_contents($this->domainFile, $newContent);

      if($save){
        return true;
      }else{
        return false;
        // throw new Exception("Unable to save updated file!");
      }

    }
    private function SaveToJson(){
      $json = json_encode([
        'soa' => $this->soa,
        'nameservers' => $this->nameservers,
        'records' => $this->records
      ], JSON_PRETTY_PRINT);
      return file_put_contents('data.json', $json);
    }

    function ReloadZones(){
      // NOTE: This function should be executed inside the docker container with the server.
      // Change this function if this class and bind server will be in different environments.
      shell_exec('rndc reload');
    }

  }

  // Functions:
  // - Initialize: Check file, parse it, check json file, compare
  // - Read: Read the file and parse
  // - create a file lock to avoid simultaneous editing
  // - modify records
  // - detect same records and manage it (there can be default behavior in this case)
  // - remove records
  // - update serial number in the SOA record with each zone update


?>
