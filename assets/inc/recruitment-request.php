<?php
require_once __DIR__ . '/app/settings.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/MailTemplate.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Méthode non autorisée");
    }

    $company = isset($_POST['company']) ? trim(preg_replace("/[^\.\-\' a-zA-Z0-9]/", "", $_POST['company'])) : "";
    $contactName = isset($_POST['contact_name']) ? trim(preg_replace("/[^\.\-\' a-zA-Z0-9]/", "", $_POST['contact_name'])) : "";
    $email = isset($_POST['email']) ? trim(preg_replace("/[^\.\-\_\@a-zA-Z0-9]/", "", $_POST['email'])) : "";
    $phone = isset($_POST['phone']) ? trim(preg_replace("/[^\+\.\-\(\) 0-9]/", "", $_POST['phone'])) : "";
    $profile = isset($_POST['profile']) ? trim(preg_replace("/[^\.\-\,\' a-zA-Z0-9]/", "", $_POST['profile'])) : "";
    $duration = isset($_POST['duration']) ? trim($_POST['duration']) : "";
    $message = isset($_POST['message']) ? trim(preg_replace("/(From:|To:|BCC:|CC:|Subject:|Content-Type:)/", "", $_POST['message'])) : "";
    $adminEmail = trim((string) Env::get('ADMIN_EMAIL', 'contact@mhtechconsulting.com'));

    if ($adminEmail === '' || stripos($adminEmail, 'scriptfusions') !== false) {
        $adminEmail = 'contact@mhtechconsulting.com';
    }

    if (empty($company) || empty($contactName) || empty($email) || empty($phone) || empty($profile) || empty($duration) || empty($message)) {
        throw new Exception("Veuillez remplir tous les champs obligatoires");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Adresse email invalide");
    }

    $allowedDurations = ['1-3', '3-6', '6-12', '12+', 'permanent'];
    if (!in_array($duration, $allowedDurations, true)) {
        throw new Exception("Durée de mission invalide");
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $db = Database::getInstance();
    $requestId = $db->insert('recruitment_requests', [
        'company' => $company,
        'contact_name' => $contactName,
        'email' => $email,
        'phone' => $phone,
        'profile' => $profile,
        'duration' => $duration,
        'message' => $message,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
        'status' => 'new'
    ]);

    $durationLabels = [
        '1-3' => '1 à 3 mois',
        '3-6' => '3 à 6 mois',
        '6-12' => '6 à 12 mois',
        '12+' => '12 mois et plus',
        'permanent' => 'CDI'
    ];
    $durationLabel = $durationLabels[$duration] ?? $duration;

    try {
        MailTemplate::resetMailer($mail);
        $mail->addAddress($email, $contactName);
        $mail->Subject = 'Demande de recrutement reçue - MHTECH Consulting';
        $userTemplate = MailTemplate::build($mail, [
            'preheader' => 'Votre demande de recrutement a bien été reçue par MHTECH Consulting.',
            'eyebrow' => 'Staffing IT',
            'title' => 'Votre demande est bien enregistrée',
            'intro' => 'Bonjour ' . $contactName . ', merci pour votre confiance. Notre équipe analyse votre besoin et reviendra vers vous rapidement avec une réponse adaptée.',
            'badge' => 'Demande RH reçue',
            'cards' => [
                [
                    'title' => 'Récapitulatif de votre besoin',
                    'rows' => [
                        ['label' => 'Entreprise', 'value' => $company],
                        ['label' => 'Contact', 'value' => $contactName],
                        ['label' => 'Email', 'value' => $email],
                        ['label' => 'Téléphone', 'value' => $phone],
                        ['label' => 'Profil recherché', 'value' => $profile],
                        ['label' => 'Durée de mission', 'value' => $durationLabel],
                        ['label' => 'Référence', 'value' => '#' . $requestId]
                    ]
                ],
                [
                    'title' => 'Description du besoin',
                    'message' => $message
                ]
            ],
            'steps' => [
                'Qualification du besoin par notre équipe.',
                'Sélection de profils adaptés à votre contexte.',
                'Prise de contact pour organiser la suite du process.'
            ],
            'closing' => "Un consultant MHTECH Consulting reviendra vers vous dans les plus brefs délais."
        ]);
        $mail->Body = $userTemplate['html'];
        $mail->AltBody = $userTemplate['text'];
        $mail->send();

        MailTemplate::resetMailer($mail);
        $mail->addAddress($adminEmail);
        $mail->addReplyTo($email, $contactName);
        $mail->Subject = 'Nouvelle demande de recrutement - ' . $company;
        $adminTemplate = MailTemplate::build($mail, [
            'preheader' => 'Une nouvelle demande de recrutement attend votre traitement.',
            'eyebrow' => 'Alerte recrutement',
            'title' => 'Nouvelle demande de recrutement',
            'intro' => 'Une nouvelle entreprise a soumis un besoin en staffing IT. Répondez directement à ce message pour contacter le demandeur.',
            'badge' => 'Suivi commercial',
            'cards' => [
                [
                    'title' => 'Entreprise et contact',
                    'rows' => [
                        ['label' => 'Entreprise', 'value' => $company],
                        ['label' => 'Contact', 'value' => $contactName],
                        ['label' => 'Email', 'value' => $email, 'href' => 'mailto:' . $email],
                        ['label' => 'Téléphone', 'value' => $phone, 'href' => 'tel:' . preg_replace('/\s+/', '', $phone)]
                    ]
                ],
                [
                    'title' => 'Mission demandée',
                    'rows' => [
                        ['label' => 'Profil recherché', 'value' => $profile],
                        ['label' => 'Durée', 'value' => $durationLabel],
                        ['label' => 'ID en base', 'value' => '#' . $requestId],
                        ['label' => 'Date', 'value' => date('d/m/Y H:i:s')],
                        ['label' => 'IP', 'value' => (string) ($ipAddress ?? 'Non disponible')]
                    ]
                ],
                [
                    'title' => 'Description du besoin',
                    'message' => $message
                ]
            ],
            'closing' => "La demande a été enregistrée en base de données et attend maintenant un suivi commercial."
        ]);
        $mail->Body = $adminTemplate['html'];
        $mail->AltBody = $adminTemplate['text'];
        $mail->send();
    } catch (Exception $mailError) {
        error_log("Email Error (recruitment-request): " . $mailError->getMessage());
    }

    $db->insert('activity_logs', [
        'table_name' => 'recruitment_requests',
        'record_id' => $requestId,
        'action' => 'insert',
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent
    ]);

    echo "<div class='alert alert-success' role='alert'>
        <strong>Demande envoyée avec succès !</strong><br>
        Votre numéro de demande est <strong>#{$requestId}</strong>.<br>
        Nous vous contacterons dans les 48 heures.
    </div>";
} catch (Exception $e) {
    error_log("Recruitment Request Error: " . $e->getMessage());
    echo "<div class='alert alert-danger' role='alert'>
        <strong>Erreur:</strong> " . htmlspecialchars($e->getMessage()) . "
    </div>";
}
