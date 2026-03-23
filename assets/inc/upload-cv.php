<?php
require_once __DIR__ . '/app/settings.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/MailTemplate.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Méthode non autorisée");
    }

    $name = isset($_POST['name']) ? trim(preg_replace("/[^\.\-\' a-zA-Z0-9]/", "", $_POST['name'])) : "";
    $email = isset($_POST['email']) ? trim(preg_replace("/[^\.\-\_\@a-zA-Z0-9]/", "", $_POST['email'])) : "";
    $phone = isset($_POST['phone']) ? trim(preg_replace("/[^\+\.\-\(\) 0-9]/", "", $_POST['phone'])) : "";
    $position = isset($_POST['position']) ? trim(preg_replace("/[^\.\-\' a-zA-Z0-9]/", "", $_POST['position'])) : "";
    $message = isset($_POST['message']) ? trim(preg_replace("/(From:|To:|BCC:|CC:|Subject:|Content-Type:)/", "", $_POST['message'])) : "";
    $adminEmail = trim((string) Env::get('ADMIN_EMAIL', 'contact@mhtechconsulting.com'));

    if ($adminEmail === '' || stripos($adminEmail, 'scriptfusions') !== false) {
        $adminEmail = 'contact@mhtechconsulting.com';
    }

    if (empty($name) || empty($email) || empty($phone) || empty($position)) {
        throw new Exception("Veuillez remplir tous les champs obligatoires");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Adresse email invalide");
    }

    if (!isset($_FILES['cv']) || $_FILES['cv']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception("Veuillez joindre votre CV");
    }

    $file = $_FILES['cv'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille maximale autorisée',
            UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale du formulaire',
            UPLOAD_ERR_PARTIAL => 'Le fichier n’a été que partiellement téléchargé',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
            UPLOAD_ERR_CANT_WRITE => 'Échec de l’écriture du fichier sur le disque',
            UPLOAD_ERR_EXTENSION => 'Une extension PHP a arrêté l’upload du fichier'
        ];
        throw new Exception($uploadErrors[$file['error']] ?? "Erreur lors de l'upload du fichier");
    }

    $maxFileSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxFileSize) {
        throw new Exception("Le fichier CV ne doit pas dépasser 5 MB");
    }

    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new Exception("Format de fichier non autorisé. Formats acceptés : PDF, DOC, DOCX");
    }

    $allowedExtensions = ['pdf', 'doc', 'docx'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($fileExtension, $allowedExtensions, true)) {
        throw new Exception("Extension de fichier non autorisée");
    }

    $uploadDir = __DIR__ . '/uploads/cvs/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception("Impossible de créer le dossier de stockage");
    }

    $htaccessFile = $uploadDir . '.htaccess';
    if (!file_exists($htaccessFile)) {
        file_put_contents($htaccessFile, "deny from all");
    }

    $filename = uniqid('cv_', true) . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception("Erreur lors de l'enregistrement du fichier");
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $db = Database::getInstance();
    $cvId = $db->insert('cv_submissions', [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'position' => $position,
        'cv_filename' => $filename,
        'cv_original_name' => $file['name'],
        'cv_file_size' => $file['size'],
        'cv_mime_type' => $mimeType,
        'message' => $message,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
        'status' => 'new'
    ]);

    try {
        MailTemplate::resetMailer($mail);
        $mail->addAddress($email, $name);
        $mail->Subject = 'Candidature reçue - MHTECH Consulting';
        $userTemplate = MailTemplate::build($mail, [
            'preheader' => 'Votre CV a bien été reçu par MHTECH Consulting.',
            'eyebrow' => 'Staffing IT',
            'title' => 'Votre candidature est bien enregistrée',
            'intro' => 'Bonjour ' . $name . ', nous avons bien reçu votre CV pour le poste de ' . $position . '. Notre équipe recrutement va l’étudier rapidement.',
            'badge' => 'CV reçu',
            'cards' => [
                [
                    'title' => 'Récapitulatif de votre candidature',
                    'rows' => [
                        ['label' => 'Nom', 'value' => $name],
                        ['label' => 'Email', 'value' => $email],
                        ['label' => 'Téléphone', 'value' => $phone],
                        ['label' => 'Poste visé', 'value' => $position],
                        ['label' => 'Fichier transmis', 'value' => $file['name']],
                        ['label' => 'Référence', 'value' => '#' . $cvId]
                    ]
                ]
            ],
            'steps' => [
                'Analyse de votre CV par notre équipe.',
                'Vérification de l’adéquation avec les besoins en cours.',
                'Prise de contact si votre profil correspond à une opportunité.'
            ],
            'closing' => "Merci pour votre intérêt envers MHTECH Consulting.\nNous reviendrons vers vous si votre profil correspond à un besoin actif."
        ]);
        $mail->Body = $userTemplate['html'];
        $mail->AltBody = $userTemplate['text'];
        $mail->send();

        MailTemplate::resetMailer($mail);
        $mail->addAddress($adminEmail);
        $mail->addReplyTo($email, $name);
        $mail->addAttachment($filePath, $file['name']);
        $mail->Subject = 'Nouveau CV reçu - ' . $position;
        $adminTemplate = MailTemplate::build($mail, [
            'preheader' => 'Un nouveau CV vient d’être reçu sur la page Staffing.',
            'eyebrow' => 'Alerte recrutement',
            'title' => 'Nouveau CV reçu',
            'intro' => 'Une nouvelle candidature a été enregistrée et le CV original est joint à cet email.',
            'badge' => 'Action RH',
            'cards' => [
                [
                    'title' => 'Candidat',
                    'rows' => [
                        ['label' => 'Nom', 'value' => $name],
                        ['label' => 'Email', 'value' => $email, 'href' => 'mailto:' . $email],
                        ['label' => 'Téléphone', 'value' => $phone, 'href' => 'tel:' . preg_replace('/\s+/', '', $phone)],
                        ['label' => 'Poste recherché', 'value' => $position]
                    ]
                ],
                [
                    'title' => 'Pièce jointe et suivi',
                    'rows' => [
                        ['label' => 'Fichier joint', 'value' => $file['name']],
                        ['label' => 'Taille', 'value' => round($file['size'] / 1024, 2) . ' KB'],
                        ['label' => 'Date', 'value' => date('d/m/Y H:i:s')],
                        ['label' => 'ID en base', 'value' => '#' . $cvId]
                    ]
                ],
                [
                    'title' => 'Message du candidat',
                    'message' => $message !== '' ? $message : 'Aucun message complémentaire.'
                ]
            ],
            'closing' => "Le dossier a été enregistré en base de données et le CV est attaché à cet email."
        ]);
        $mail->Body = $adminTemplate['html'];
        $mail->AltBody = $adminTemplate['text'];
        $mail->send();
    } catch (Exception $mailError) {
        error_log("Email Error (upload-cv): " . $mailError->getMessage());
    }

    echo "<div class='alert alert-success' role='alert'>
        Votre CV a été envoyé avec succès ! Nous vous contacterons rapidement.
    </div>";
} catch (Exception $e) {
    error_log("Upload CV Error: " . $e->getMessage());
    echo "<div class='alert alert-danger' role='alert'>
        Erreur: " . htmlspecialchars($e->getMessage()) . "
    </div>";
}
