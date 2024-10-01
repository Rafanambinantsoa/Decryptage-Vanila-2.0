<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use phpseclib\Crypt\TripleDES;
use DateTime;
use Exception;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator as FacadesValidator;

class PaymentController extends Controller
{
    //Pour le message de success
    public function success(Request $request)
    {
        // Récupérer tous les paramètres de la requête
        $allQueries = $request->query(); // ou $request->all()

        $private_key = 'c2feffa73e0933a6220ed9296bd7551c77a19d35ec23643016'; // Clé privée obtenue de la plateforme AriaryNet

        // Initialiser le décryptage TripleDES avec mode CBC et IV
        $des = new TripleDES();
        $des->setKey($private_key);

        // Tableau pour stocker les données décryptées
        $decryptedData = [];

        try {
            foreach ($allQueries as $key => $value) {
                // Tentative de décryptage des valeurs
                $decryptedValue = $des->decrypt($value);
                $decryptedData[ucfirst($key)] = $decryptedValue;
            }
        } catch (Exception $e) {
            // En cas d'erreur de décryptage, logguer l'exception
            Log::error('Erreur lors du décryptage: ' . $e->getMessage());
            return response()->json(['error' => 'Erreur lors du décryptage'], 500);
        }

        // Construire l'affichage des données décryptées sous forme de texte structuré
        $messageContent = "Les détails de la requête :\n";
        foreach ($decryptedData as $key => $value) {
            $messageContent .= "$key : $value\n";
        }

        // Envoyer l'email avec les paramètres dans le corps
        Mail::raw($messageContent, function ($message) {
            $message->to('akutagawakarim@gmail.com')
                ->subject('Détails de la transaction - Succès');
        });

        // Retourner une réponse HTTP 200 avec un message de confirmation
        return response()->json(['message' => 'Détails envoyés avec succès.'], 200);
    }

    //Pour le demarage du paiment vanila pay 
    public function initPayment(Request $request)
    {
        $validator = FacadesValidator::make($request->all(), [
            "nom" => 'required',
            "total" => 'required',
            "id" => 'required' , 
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response([
                "Message" => "There is an empty things dude "
            ]);
        }

        // Exemple de données à envoyer
        $total = $request->total; // Montant à payer
        $nom = $request->nom; // Nom du payeur
        $mail = 'mail@mail.com'; // Adresse email du payeur
        $site_url = 'https://decryptage-vanila-2-0.onrender.com'; // URL du site e-commerce
        $ip = $request->ip(); // Adresse IP du client (Laravel peut détecter l'IP)
        $now = new DateTime(); // Date du paiement
        $daty = $now->format('Y-m-d'); // Formattage de date

        // Clés de sécurité
        $public_key = '4c36cff9f00736b959c123ffcabf853aeae44d956b66f35f43'; // Clé publique obtenue de la plateforme AriaryNet
        $private_key = 'c2feffa73e0933a6220ed9296bd7551c77a19d35ec23643016'; // Clé privée obtenue de la plateforme AriaryNet

        // Authentification pour obtenir le token
        $auth_params = [
            'client_id' => '399_4umss3k06x6oc8gcg04gcw4wokkokw080wssg40k040oksk8c4',
            'client_secret' => '2fbaa5olmlxc8o4ssco40oosk840840k40ckk0008k8wggc80w',
            'grant_type' => 'client_credentials'
        ];

        $curl = curl_init();
        $url = 'https://pro.ariarynet.com/oauth/v2/token';

        curl_setopt($curl, CURLOPT_HTTPHEADER, []);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $auth_params);
        curl_setopt($curl, CURLOPT_URL, $url);
        $result = curl_exec($curl);
        curl_close($curl);

        // Obtenir le token
        $json = json_decode($result);
        // dd($json);
        $token = $json->access_token;
        // dd($token);

        // Headers pour les requêtes API
        $headers = ["Authorization:Bearer " . $token];

        // Cryptage TripleDES avec mode CBC et IV
        $des = new TripleDES();
        $des->setKey($public_key);
        $des->setIV(str_repeat("\0", 8)); // IV de 8 octets pour TripleDES
        // $des->setMode(TripleDES::MODE_CBC); // Mode CBC

        $id = $request->id;
        $email = $request->email;

        // Données à envoyer pour la transaction
        $params_to_send = array(
            "unitemonetaire" => "Ar",
            "adresseip"      => $request->ip(), // Utilisation de l'IP réelle du client
            "date"           => $daty,
            "idpanier"       => uniqid(), // ID de panier unique généré
            "montant"        => $total,
            "nom"            => $email,
            "reference"      => $id,
            "site_url" => $site_url // Référence interne optionnelle
        );

        // Chiffrement des paramètres
        $params_crypt = $des->encrypt(json_encode($params_to_send));
        // Paramètres envoyés à l'API
        // $kim = array(
        //     "params"   => $params_crypt,
        //     "site_url" => $site_url
        // );
        // $params['site_url'] = $site_url;

        // Appel de l'API pour obtenir l'ID de paiement
        $curl = curl_init();
        $url = 'https://pro.ariarynet.com/api/paiements';

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $params_to_send); // Envoyer les paramètres en tant que JSON
        curl_setopt($curl, CURLOPT_URL, $url);
        $result = curl_exec($curl);

        // dd($result);

        if ($result === false) {
            dd('Erreur cURL : ' . curl_error($curl));
        }
        curl_close($curl);

        // dd("Résultat cURL : " . $result);

        // Décryptage de l'ID de paiement (vérifiez d'abord si $result est correct)
        $des->setKey($private_key);

        $id = $des->decrypt($result);
        // dd($id);
        return redirect()->away("https://moncompte.ariarynet.com/payer/{$id}");
    }

    //Pour le message de success
    public function failed(Request $request)
    {
        // Récupérer tous les paramètres de la requête
        $allQueries = $request->query(); // ou $request->all()

        $private_key = 'c2feffa73e0933a6220ed9296bd7551c77a19d35ec23643016'; // Clé privée obtenue de la plateforme AriaryNet

        // Initialiser le décryptage TripleDES avec mode CBC et IV
        $des = new TripleDES();
        $des->setKey($private_key);

        // Tableau pour stocker les données décryptées
        $decryptedData = [];

        try {
            foreach ($allQueries as $key => $value) {
                // Tentative de décryptage des valeurs
                $decryptedValue = $des->decrypt($value);
                $decryptedData[ucfirst($key)] = $decryptedValue;
            }
        } catch (Exception $e) {
            // En cas d'erreur de décryptage, logguer l'exception
            Log::error('Erreur lors du décryptage: ' . $e->getMessage());
            return response()->json(['error' => 'Erreur lors du décryptage'], 500);
        }

        // Construire l'affichage des données décryptées sous forme de texte structuré
        $messageContent = "Les détails de la requête ERROR :\n";
        foreach ($decryptedData as $key => $value) {
            $messageContent .= "$key : $value\n";
        }

        // Envoyer l'email avec les paramètres dans le corps
        Mail::raw($messageContent, function ($message) {
            $message->to('akutagawakarim@gmail.com')
                ->subject('Détails de la transaction - ERROR');
        });

        // Retourner une réponse HTTP 200 avec un message de confirmation
        return response()->json(['message' => 'Détails envoyés avec succès.'], 200);
    }

    public function withdraw(Request $request)
    {
        // Récupérer les informations de retrait depuis le formulaire ou la base de données
        $account = 'TON_NUMERO_COMPTE';
        $siteUrl = 'https://tonsite.com';
        $amount = $request->input('amount'); // Le montant de retrait
        $recipient = $request->input('recipient'); // Numéro de téléphone
        $reference = 'ref_' . uniqid(); // Référence unique pour ce retrait

        // Envoyer la requête POST à l'API Vanilla Pay pour le retrait
        $response = Http::withHeaders([
            'Authorization' => 'Bearer TON_TOKEN_SECRETE', // Jeton d'authentification
        ])->post('https://moncompte.ariarynet.com/api/v2/withdrawal', [
            'account' => $account,
            'site_url' => $siteUrl,
            'amount' => $amount,
            'recipient' => $recipient,
            'reference' => $reference,
        ]);

        // Vérifier la réponse
        if ($response->successful()) {
            // Traitement du succès
            $result = $response->json();
            return response()->json([
                'status' => 'success',
                'message' => 'Retrait demandé avec succès',
                'data' => $result
            ], 200);
        } else {
            // Traitement de l'échec
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la demande de retrait',
                'error' => $response->body()
            ], 500);
        }
    }
}
