<?php

namespace App\Services;

use App\Models\RegistrationDossier;
use App\Support\ArabicPdf;

class RegistrationDossierPdfService
{
    public function render(RegistrationDossier $dossier): string
    {
        return ArabicPdf::render(view('admin-views.amial.registration-dossiers.pdf', [
            'dossier' => $dossier,
            'payload' => (array) $dossier->payload_encrypted,
        ])->render(), ['format' => 'A4', 'margin' => 12]);
    }
}
