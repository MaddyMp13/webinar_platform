(function (root) {
  "use strict";
  const countryCodes =
    "AF AX AL DZ AS AD AO AI AQ AG AR AM AW AU AT AZ BS BH BD BB BY BE BZ BJ BM BT BO BQ BA BW BV BR IO BN BG BF BI CV KH CM CA KY CF TD CL CN CX CC CO KM CG CD CK CR CI HR CU CW CY CZ DK DJ DM DO EC EG SV GQ ER EE SZ ET FK FO FJ FI FR GF PF TF GA GM GE DE GH GI GR GL GD GP GU GT GG GN GW GY HT HM VA HN HK HU IS IN ID IR IQ IE IM IL IT JM JP JE JO KZ KE KI KP KR KW KG LA LV LB LS LR LY LI LT LU MO MG MW MY MV ML MT MH MQ MR MU YT MX FM MD MC MN ME MS MA MZ MM NA NR NP NL NC NZ NI NE NG NU NF MK MP NO OM PK PW PS PA PG PY PE PH PN PL PT PR QA RE RO RU RW BL SH KN LC MF PM VC WS SM ST SA SN RS SC SL SG SX SK SI SB SO ZA GS SS ES LK SD SR SJ SE CH SY TW TJ TZ TH TL TG TK TO TT TN TR TM TC TV UG UA AE GB US UM UY UZ VU VE VN VG VI WF EH YE ZM ZW".split(
      " ",
    );
  const privacyVersion = "registration-2026-10-v1";
  function normalize(values) {
    const result = {};
    for (const field of [
      "firstName",
      "lastName",
      "email",
      "phone",
      "company",
      "jobTitle",
      "country",
    ]) {
      result[field] =
        typeof values[field] === "string" ? values[field].trim() : "";
    }
    result.email = result.email.toLowerCase();
    result.privacyConsent = values.privacyConsent === true;
    result.marketingOptIn = values.marketingOptIn === true;
    return result;
  }
  function validate(values) {
    const data = normalize(values);
    const errors = {};
    const limits = {
      firstName: 80,
      lastName: 80,
      email: 254,
      phone: 30,
      company: 120,
      jobTitle: 100,
    };
    for (const [field, limit] of Object.entries(limits)) {
      if (field !== "jobTitle" && !data[field])
        errors[field] = "Please complete this field.";
      else if (data[field].length > limit)
        errors[field] = `Use no more than ${limit} characters.`;
    }
    if (data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email))
      errors.email = "Enter a valid email address, such as you@company.com.";
    const digits = data.phone.replace(/\D/g, "").length;
    if (
      data.phone &&
      (!/^\+?[\d\s().-]+$/.test(data.phone) || digits < 7 || digits > 15)
    )
      errors.phone = "Enter a valid phone number with 7–15 digits.";
    if (!countryCodes.includes(data.country))
      errors.country = "Please select your country or region.";
    if (!data.privacyConsent)
      errors.privacyConsent = "Please accept the privacy notice to continue.";
    return errors;
  }
  const api = { countryCodes, privacyVersion, normalize, validate };
  if (typeof module !== "undefined" && module.exports) module.exports = api;
  else root.RegistrationRules = api;
})(globalThis);
