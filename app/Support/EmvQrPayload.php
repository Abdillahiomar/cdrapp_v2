<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Lecture et vérification d'un contenu de QR de paiement au format EMVCo
 * (ex. 000201010211…6304E9B5).
 *
 * Le contenu est une suite de champs TLV : 2 chiffres de tag, 2 chiffres de
 * longueur, puis la valeur. Le dernier champ (tag 63) est un CRC16-CCITT
 * calculé sur tout ce qui précède, "6304" compris : il permet de détecter
 * une erreur de copier-coller (caractère manquant, ajouté ou modifié).
 *
 * Les messages d'erreur sont destinés aux utilisateurs métier : ils disent
 * quoi corriger, pas seulement ce qui est faux.
 */
class EmvQrPayload
{
    public const MAX_LENGTH = 512;

    /** @var string[] */
    public array $errors = [];

    /** @var array<string, string> tag => valeur */
    public array $fields = [];

    private function __construct(public readonly string $payload)
    {
    }

    public static function parse(string $raw): self
    {
        $qr = new self(trim($raw));
        $qr->validate();

        return $qr;
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    private function validate(): void
    {
        $p = $this->payload;

        if ($p === '') {
            $this->errors[] = 'Le contenu est vide.';
            return;
        }

        if (preg_match('/\s/', $p)) {
            $this->errors[] = 'Le contenu contient des espaces ou des retours à la ligne. Copiez la chaîne d\'un seul bloc, sans espace.';
            return;
        }

        if (strlen($p) > self::MAX_LENGTH) {
            $this->errors[] = 'Le contenu est trop long (' . strlen($p) . ' caractères, maximum ' . self::MAX_LENGTH . ').';
            return;
        }

        // Excel convertit une longue suite de chiffres en nombre (2,01E+95) et perd les zéros de tête
        if (preg_match('/^\d+([.,]\d+)?E\+\d+$/i', $p)) {
            $this->errors[] = 'Le contenu a été transformé en nombre par Excel (notation « E+ »). Mettez la colonne au format Texte et recollez la chaîne d\'origine.';
            return;
        }

        if (!str_starts_with($p, '000201')) {
            $this->errors[] = 'Le contenu doit commencer par « 000201 » (en-tête des QR de paiement). '
                . 'Il commence par « ' . substr($p, 0, 8) . '… » : vérifiez qu\'aucun caractère n\'a été ajouté ou oublié au début.';
            return;
        }

        // Lecture des champs TLV
        $pos = 0;
        $len = strlen($p);

        while ($pos < $len) {
            $header = substr($p, $pos, 4);

            if (strlen($header) < 4 || !ctype_digit($header)) {
                $this->errors[] = "Structure invalide à partir du caractère " . ($pos + 1) . " (« " . substr($p, $pos, 10) . "… »). "
                    . 'Le contenu a probablement été coupé ou mal copié.';
                return;
            }

            $tag      = substr($header, 0, 2);
            $fieldLen = (int) substr($header, 2, 2);
            $value    = substr($p, $pos + 4, $fieldLen);

            if (strlen($value) < $fieldLen) {
                $this->errors[] = "Le champ {$tag} annonce {$fieldLen} caractères mais le contenu s'arrête avant. "
                    . 'La fin de la chaîne a probablement été coupée lors du copier-coller.';
                return;
            }

            $this->fields[$tag] = $value;
            $pos += 4 + $fieldLen;

            if ($tag === '63') {
                break;
            }
        }

        if (!isset($this->fields['63'])) {
            $this->errors[] = 'Le code de contrôle (champ 63, 4 derniers caractères) est absent. La fin de la chaîne a probablement été coupée.';
            return;
        }

        if ($pos !== $len) {
            $this->errors[] = 'Des caractères en trop se trouvent après le code de contrôle (« ' . substr($p, $pos, 10) . ' »). Supprimez-les.';
            return;
        }

        $expected = self::crc16(substr($p, 0, -4));
        $found    = strtoupper($this->fields['63']);

        if ($expected !== $found) {
            $this->errors[] = "Le code de contrôle ne correspond pas au contenu (attendu {$expected}, trouvé {$found}). "
                . 'Un caractère a été modifié, ajouté ou oublié pendant le copier-coller. Recopiez la chaîne d\'origine.';
        }
    }

    /**
     * Résumé lisible pour que l'utilisateur confirme qu'il a collé le bon QR.
     *
     * @return array<string, string>
     */
    public function summary(): array
    {
        $summary = [];

        if (isset($this->fields['01'])) {
            $summary['Type de QR'] = match ($this->fields['01']) {
                '11'    => 'Statique (réutilisable)',
                '12'    => 'Dynamique (usage unique)',
                default => $this->fields['01'],
            };
        }

        // Informations du compte marchand : tags 26 à 51
        foreach ($this->fields as $tag => $value) {
            if ((int) $tag >= 26 && (int) $tag <= 51) {
                $sub = self::subFields($value);
                if (isset($sub['00'])) {
                    $summary['Identifiant'] = $sub['00'];
                }
                $others = array_diff_key($sub, ['00' => true]);
                if ($others) {
                    $summary['Compte marchand'] = implode(' · ', $others);
                }
                break;
            }
        }

        if (!empty($this->fields['59'])) {
            $summary['Nom du marchand'] = $this->fields['59'];
        }
        if (!empty($this->fields['60'])) {
            $summary['Ville'] = $this->fields['60'];
        }
        if (!empty($this->fields['54'])) {
            $summary['Montant'] = $this->fields['54'];
        }

        // Champ 80 (données D-Money) : sous-champ 02 = date de création AAAAMMJJHHMMSS
        if (isset($this->fields['80'])) {
            $sub = self::subFields($this->fields['80']);
            if (isset($sub['02']) && preg_match('/^\d{14}$/', $sub['02'])) {
                try {
                    $summary['Créé le'] = Carbon::createFromFormat('YmdHis', $sub['02'])->format('d/m/Y H:i:s');
                } catch (\Throwable) {
                    // date illisible : on ne l'affiche pas
                }
            }
        }

        $summary['Code de contrôle'] = ($this->fields['63'] ?? '') . ' ✓';

        return $summary;
    }

    /**
     * Découpe une valeur composée en sous-champs TLV (tolérant : s'arrête à la première incohérence).
     *
     * @return array<string, string>
     */
    private static function subFields(string $value): array
    {
        $out = [];
        $pos = 0;

        while ($pos + 4 <= strlen($value) && ctype_digit(substr($value, $pos, 4))) {
            $tag      = substr($value, $pos, 2);
            $len      = (int) substr($value, $pos + 2, 2);
            $out[$tag] = substr($value, $pos + 4, $len);
            $pos     += 4 + $len;
        }

        return $out;
    }

    /**
     * CRC16-CCITT (polynôme 0x1021, valeur initiale 0xFFFF), comme exigé par EMVCo.
     */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        foreach (str_split($data) as $char) {
            $crc ^= ord($char) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }
}
