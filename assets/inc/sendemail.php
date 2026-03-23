<?php
require_once __DIR__ . '/app/settings.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/MailTemplate.php';

try {
    $name = isset($_POST['name']) ? preg_replace("/[^\.\-\' a-zA-Z0-9]/", "", $_POST['name']) : "";
    $senderEmail = isset($_POST['email']) ? preg_replace("/[^\.\-\_\@a-zA-Z0-9]/", "", $_POST['email']) : "";
    $phone = isset($_POST['phone']) ? preg_replace("/[^\+\.\-\(\) 0-9]/", "", $_POST['phone']) : "";
    $subject = isset($_POST['subject']) ? preg_replace("/[^\.\-\_\@a-zA-Z0-9\s]/", "", $_POST['subject']) : "";
    $message = isset($_POST['message']) ? preg_replace("/(From:|To:|BCC:|CC:|Subject:|Content-Type:)/", "", $_POST['message']) : "";
    $requestType = isset($_POST['request_type']) ? preg_replace("/[^a-zA-Z0-9_]/", "", $_POST['request_type']) : "";
    $adminEmail = trim((string) Env::get('ADMIN_EMAIL', 'contact@mhtechconsulting.com'));

    if ($adminEmail === '' || stripos($adminEmail, 'scriptfusions') !== false) {
        $adminEmail = 'contact@mhtechconsulting.com';
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $refererPath = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);
    $refererPage = basename($refererPath ?: '');

    $newsletterSources = [
        'index.html' => 'newsletter_home',
        'about.html' => 'newsletter_about',
        'services.html' => 'newsletter_services',
        'staffing.html' => 'newsletter_staffing',
        'contact.html' => 'newsletter_contact',
        'blog.html' => 'newsletter_blog',
        'testimonials.html' => 'newsletter_testimonials'
    ];
    $newsletterSource = $newsletterSources[$refererPage] ?? 'newsletter';

    $source = 'unknown';
    if (!empty($name) && !empty($message)) {
        if (!empty($requestType)) {
            $source = 'contact_page';
        } elseif (!empty($subject)) {
            $source = 'staffing_page';
        } else {
            $source = 'chat_popup';
        }
    }

    if (empty($senderEmail)) {
        echo "<div class='alert alert-danger' role='alert'>
            Veuillez remplir tous les champs obligatoires.
        </div>";
        return;
    }

    if (empty($name) || empty($message)) {
        try {
            $db = Database::getInstance();
            $subscriptionId = $db->insert('newsletter_subscriptions', [
                'email' => $senderEmail,
                'source' => $newsletterSource,
                'ip_address' => $ipAddress
            ]);

            $db->insert('activity_logs', [
                'table_name' => 'newsletter_subscriptions',
                'record_id' => $subscriptionId,
                'action' => 'insert',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent
            ]);
        } catch (Exception $dbError) {
            error_log("Newsletter DB Error: " . $dbError->getMessage());
        }

        try {
            MailTemplate::resetMailer($mail);
            $mail->addAddress($senderEmail);
            $mail->Subject = 'Abonnement confirmé - Newsletter MHTECH Consulting';
            $userTemplate = MailTemplate::build($mail, [
                'preheader' => 'Votre inscription à la newsletter MHTECH Consulting est confirmée.',
                'eyebrow' => 'Newsletter',
                'title' => 'Inscription confirmée',
                'intro' => 'Merci pour votre abonnement. Vous recevrez nos actualités technologiques, nos analyses et nos conseils pratiques.',
                'badge' => 'Adresse enregistrée',
                'cards' => [
                    [
                        'title' => 'Vos informations',
                        'rows' => [
                            ['label' => 'Email', 'value' => $senderEmail]
                        ],
                        'notes' => [
                            'Vous pourrez vous désinscrire à tout moment depuis nos prochains emails.'
                        ]
                    ]
                ],
                'closing' => "Merci de votre confiance.\nL'équipe MHTECH Consulting"
            ]);
            $mail->Body = $userTemplate['html'];
            $mail->AltBody = $userTemplate['text'];
            $mail->send();

            MailTemplate::resetMailer($mail);
            $mail->addAddress($adminEmail);
            $mail->addReplyTo($senderEmail);
            $mail->Subject = 'Nouvel abonnement newsletter';
            $adminTemplate = MailTemplate::build($mail, [
                'preheader' => 'Un nouvel abonné vient de rejoindre la newsletter.',
                'eyebrow' => 'Alerte admin',
                'title' => 'Nouvel abonnement newsletter',
                'intro' => 'Une nouvelle adresse email a été ajoutée à la liste de diffusion MHTECH Consulting.',
                'badge' => 'Action marketing',
                'cards' => [
                    [
                        'title' => 'Détails de l’abonnement',
                        'rows' => [
                            ['label' => 'Email', 'value' => $senderEmail, 'href' => 'mailto:' . $senderEmail],
                            ['label' => 'Source', 'value' => $newsletterSource],
                            ['label' => 'IP', 'value' => (string) ($ipAddress ?? 'Non disponible')]
                        ]
                    ]
                ],
                'closing' => "L'abonnement a été enregistré en base et ne nécessite aucune action technique."
            ]);
            $mail->Body = $adminTemplate['html'];
            $mail->AltBody = $adminTemplate['text'];
            $mail->send();
        } catch (Exception $mailError) {
            error_log("Newsletter Email Error: " . $mailError->getMessage());
        }

        echo "<div class='alert alert-success' role='alert'>
            Merci pour votre abonnement ! Vous recevrez nos actualités prochainement.
        </div>";
        return;
    }

    try {
        $db = Database::getInstance();
        $contactData = [
            'name' => $name,
            'email' => $senderEmail,
            'phone' => $phone,
            'subject' => $subject,
            'request_type' => $requestType,
            'message' => $message,
            'source' => $source,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ];

        $contactData = array_filter($contactData, function ($value) {
            return !empty($value);
        });

        $contactId = $db->insert('contacts', $contactData);

        $db->insert('activity_logs', [
            'table_name' => 'contacts',
            'record_id' => $contactId,
            'action' => 'insert',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ]);
    } catch (Exception $dbError) {
        error_log("Contact DB Error: " . $dbError->getMessage());
        throw new Exception("Erreur lors de l'enregistrement de votre demande");
    }

    try {
        $requestTypeLabel = $requestType !== '' ? ucwords(str_replace('_', ' ', $requestType)) : '';

        $userRows = [
            ['label' => 'Nom', 'value' => $name],
            ['label' => 'Email', 'value' => $senderEmail]
        ];
        if (!empty($phone)) {
            $userRows[] = ['label' => 'Téléphone', 'value' => $phone];
        }
        if (!empty($subject)) {
            $userRows[] = ['label' => 'Sujet', 'value' => $subject];
        }
        if (!empty($requestTypeLabel)) {
            $userRows[] = ['label' => 'Type de demande', 'value' => $requestTypeLabel];
        }

        MailTemplate::resetMailer($mail);
        $mail->addAddress($senderEmail, $name);
        $mail->Subject = 'Votre demande a bien été reçue - MHTECH Consulting';
        $userTemplate = MailTemplate::build($mail, [
            'preheader' => 'Votre message a bien été transmis à MHTECH Consulting.',
            'eyebrow' => 'Contact',
            'title' => 'Votre message est bien arrivé',
            'intro' => 'Bonjour ' . $name . ', merci pour votre prise de contact. Notre équipe examine votre demande et reviendra vers vous dans les plus brefs délais.',
            'badge' => 'Demande reçue',
            'cards' => [
                [
                    'title' => 'Récapitulatif',
                    'rows' => $userRows
                ],
                [
                    'title' => 'Votre message',
                    'message' => $message
                ]
            ],
            'closing' => "Nous vous répondrons rapidement avec la suite adaptée à votre besoin.\nL'équipe MHTECH Consulting"
        ]);
        $mail->Body = $userTemplate['html'];
        $mail->AltBody = $userTemplate['text'];
        $mail->send();

        $adminRows = [
            ['label' => 'Nom', 'value' => $name],
            ['label' => 'Email', 'value' => $senderEmail, 'href' => 'mailto:' . $senderEmail]
        ];
        if (!empty($phone)) {
            $adminRows[] = ['label' => 'Téléphone', 'value' => $phone, 'href' => 'tel:' . preg_replace('/\s+/', '', $phone)];
        }
        if (!empty($subject)) {
            $adminRows[] = ['label' => 'Sujet', 'value' => $subject];
        }
        if (!empty($requestTypeLabel)) {
            $adminRows[] = ['label' => 'Type de demande', 'value' => $requestTypeLabel];
        }
        $adminRows[] = ['label' => 'Source', 'value' => $source];
        $adminRows[] = ['label' => 'ID en base', 'value' => '#' . $contactId];

        MailTemplate::resetMailer($mail);
        $mail->addAddress($adminEmail);
        $mail->addReplyTo($senderEmail, $name);
        $mail->Subject = 'Nouvelle demande de contact';
        $adminTemplate = MailTemplate::build($mail, [
            'preheader' => 'Une nouvelle demande de contact attend votre traitement.',
            'eyebrow' => 'Alerte admin',
            'title' => 'Nouvelle demande de contact',
            'intro' => 'Une nouvelle soumission a été enregistrée sur le site. Vous pouvez répondre directement à l’expéditeur depuis votre client email.',
            'badge' => 'Priorité commerciale',
            'cards' => [
                [
                    'title' => 'Coordonnées',
                    'rows' => $adminRows
                ],
                [
                    'title' => 'Message reçu',
                    'message' => $message
                ]
            ],
            'closing' => "La demande a été enregistrée avec succès dans la base de données."
        ]);
        $mail->Body = $adminTemplate['html'];
        $mail->AltBody = $adminTemplate['text'];
        $mail->send();
    } catch (Exception $mailError) {
        error_log("Contact Email Error: " . $mailError->getMessage());
    }

    echo "<div class='alert alert-success' role='alert'>
        Merci de nous avoir contacté ! Nous vous répondrons dans les plus brefs délais.
    </div>";
} catch (Exception $e) {
    error_log("Sendemail Error: " . $e->getMessage());
    echo "<div class='alert alert-danger' role='alert'>
        Une erreur est survenue : " . htmlspecialchars($e->getMessage()) . "
    </div>";
}
