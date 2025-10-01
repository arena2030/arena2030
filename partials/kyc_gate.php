<?php
// /partials/kyc_gate.php — Feature flag per nascondere/relaxare i campi KYC in registrazione

// Attiva modalità "registrazione minimale" (si potrà spegnere quando abilitate KYC a premio)
if (!defined('KYC_DEFERRED')) define('KYC_DEFERRED', true);

/** Elenco nomi possibili dei campi KYC (input name) che vogliamo nascondere/ignorare in registrazione */
$GLOBALS['KYC_FIELDS_MAP'] = [
  // anagrafica / indirizzo
  'codice_fiscale','cf','fiscal_code','tax_code','c_fiscale',
  'cittadinanza','citizenship',
  'via','indirizzo','address','street','street_line1',
  'civico','street_number','numero_civico',
  'citta','città','city','comune',
  'provincia','province','region','state',
  'cap','zip','postal_code',
  'nazione','country',
  // documento
  'documento','documento_tipo','doc_type',
  'numero_documento','documento_numero','doc_number',
  'data_rilascio','documento_rilascio','issue_date',
  'data_scadenza','documento_scadenza','expiry_date',
  'rilasciato_da','issuing_authority',
  // maggiorenne (checkbox)
  'maggiorenne','is_adult','over18','adult_flag'
];

/** helper */
function kyc_is_deferred(): bool { return defined('KYC_DEFERRED') && KYC_DEFERRED; }
function kyc_is_kyc_field(string $name): bool {
  $name = strtolower(trim($name));
  return in_array($name, $GLOBALS['KYC_FIELDS_MAP'], true);
}
