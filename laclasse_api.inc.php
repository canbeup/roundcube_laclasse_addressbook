<?php

/*
 * Fonctions diverses et variées des API
 */

//function p($s) {
//   echo htmlentities($s) . "<br/>";
//}

function generer_appel($url, $app_id, $api_key, $params) {
   $canonical_string = $url."?";
   $query_string = "";
   // 1. trier les paramètres
   ksort($params);
   // 2. construction de la canonical string
   foreach ($params as $k => $v) $query_string .= $k."=".urlencode ($v)."&";
   $query_string = trim($query_string, "&");
   $canonical_string.= $query_string;
   // 3. ajout du timestamp
   $timestamp = date("Y-m-d\TH:i:s");
   $canonical_string .= ";".$timestamp;
   //4. Ajout de l'identifiant d'application (connu de l'annuaire, et qui lu permet de comprendre la signature)
   $canonical_string .= ";".$app_id;
   // 5. Calcul de la signature : sha1 et Encodage Base64
   $signature = "signature=".urlencode(base64_encode(hash_hmac('sha1', $canonical_string, $api_key, true)));
   // Renvoie de la requete constituée
   $req = $url . "?" .  $query_string . ";app_id=" . $app_id . ";timestamp=" . urlencode($timestamp) . ";" . $signature;
//   p($req);
   return $req;
}

/*
  * Fonction d'envoi d'un GET http vers l'annuaire ENT.
  */
function interroger_annuaire_ENT($url_api, $app_id, $api_key, $params) {
     $url = generer_appel($url_api, $app_id, $api_key, $params);
     $ch = curl_init();
     curl_setopt($ch, CURLOPT_URL, $url);
     curl_setopt($ch, CURLOPT_ENCODING ,"");
     curl_setopt($ch, CURLOPT_HEADER, 0);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
     $data = curl_exec($ch);
     if (curl_errno($ch)) {
         return curl_error($ch);
     }
     curl_close($ch);
     return $data;
}

