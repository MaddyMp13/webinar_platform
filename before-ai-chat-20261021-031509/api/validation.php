<?php
declare(strict_types=1);

const PRIVACY_VERSION = 'registration-2026-10-v1';
const COUNTRY_CODES = 'AF AX AL DZ AS AD AO AI AQ AG AR AM AW AU AT AZ BS BH BD BB BY BE BZ BJ BM BT BO BQ BA BW BV BR IO BN BG BF BI CV KH CM CA KY CF TD CL CN CX CC CO KM CG CD CK CR CI HR CU CW CY CZ DK DJ DM DO EC EG SV GQ ER EE SZ ET FK FO FJ FI FR GF PF TF GA GM GE DE GH GI GR GL GD GP GU GT GG GN GW GY HT HM VA HN HK HU IS IN ID IR IQ IE IM IL IT JM JP JE JO KZ KE KI KP KR KW KG LA LV LB LS LR LY LI LT LU MO MG MW MY MV ML MT MH MQ MR MU YT MX FM MD MC MN ME MS MA MZ MM NA NR NP NL NC NZ NI NE NG NU NF MK MP NO OM PK PW PS PA PG PY PE PH PN PL PT PR QA RE RO RU RW BL SH KN LC MF PM VC WS SM ST SA SN RS SC SL SG SX SK SI SB SO ZA GS SS ES LK SD SR SJ SE CH SY TW TJ TZ TH TL TG TK TO TT TN TR TM TC TV UG UA AE GB US UM UY UZ VU VE VN VG VI WF EH YE ZM ZW';

function registration_values(array $raw): array
{
    $data = [];
    $errors = [];
    $limits = ['firstName'=>80,'lastName'=>80,'email'=>254,'phone'=>30,'company'=>120,'jobTitle'=>100,'country'=>2];
    foreach ($limits as $field => $limit) {
        $data[$field] = isset($raw[$field]) && is_string($raw[$field]) ? trim($raw[$field]) : '';
        if ($field !== 'jobTitle' && $data[$field] === '') $errors[$field] = 'Please complete this field.';
        elseif (mb_strlen($data[$field], 'UTF-8') > $limit) $errors[$field] = "Use no more than $limit characters.";
    }
    $data['email'] = strtolower($data['email']);
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'Enter a valid email address, such as you@company.com.';
    $digits = strlen(preg_replace('/\D/', '', $data['phone']));
    if ($data['phone'] !== '' && (!preg_match('/^\+?[\d\s().-]+$/D', $data['phone']) || $digits < 7 || $digits > 15))
        $errors['phone'] = 'Enter a valid phone number with 7–15 digits.';
    if (!in_array($data['country'], explode(' ', COUNTRY_CODES), true)) $errors['country'] = 'Please select your country or region.';
    if (($raw['privacyConsent'] ?? null) !== true) $errors['privacyConsent'] = 'Please accept the privacy notice to continue.';
    $data['marketingOptIn'] = ($raw['marketingOptIn'] ?? false) === true;
    if ($errors) throw new ApiError(422, 'Please review the highlighted fields.', $errors);
    if (!empty($raw['companyWebsite'])) throw new ApiError(400, 'We couldn’t submit this registration. Please try again.');
    return $data;
}
