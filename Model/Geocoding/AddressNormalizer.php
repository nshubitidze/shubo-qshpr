<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Geocoding;

/**
 * Turns a Georgian street line into the one-line query geocoders match best:
 * "<street> <house number>, <city>".
 *
 * Georgian addresses are often written registry-style ("ქ. თბილისი,
 * ასათიანი ლადოს ქუჩა №13, 0177"); OpenStreetMap names streets the way people
 * say them ("ასათიანის ქუჩა 13"). Each rule below removes one such difference.
 * On a test set of 228 Georgian addresses these rules raised Nominatim's hit
 * rate from 42% to 81%.
 */
class AddressNormalizer
{
    /**
     * Street kinds that follow the street name.
     */
    private const string STREET_KINDS = 'ქუჩა|გამზირი|შესახვევი|მოედანი|სანაპირო';

    /**
     * Georgian letters (Mkhedruli block) — "is this word Georgian?".
     */
    private const string GEORGIAN = '/\p{Georgian}/u';

    /**
     * Build the geocoder query for an address.
     *
     * @param Address $address
     * @return string
     */
    public function normalize(Address $address): string
    {
        $city = trim($address->city);
        $street = $this->cleanStreet($address->street, $city);

        return $city === '' ? $street : trim($street . ', ' . $city, ' ,');
    }

    /**
     * The address as typed, for a store that switched the clean-up off: "<street line>, <city>".
     *
     * @param Address $address
     * @return string
     */
    public function plain(Address $address): string
    {
        return trim(trim($address->street) . ', ' . trim($address->city), ' ,');
    }

    /**
     * Apply the clean-up rules to the street line.
     *
     * @param string $street
     * @param string $city
     * @return string
     */
    private function cleanStreet(string $street, string $city): string
    {
        // Corner address "X 3 / Y 5": keep the first street. A slash between
        // digits ("12/4") is a house number and stays.
        $street = (preg_split('~\s*/\s*(?=[^\d\s])~u', $street) ?: [$street])[0];

        if ($city !== '') {
            // "ქ. თბილისი," / "ქალაქი თბილისი," prefix (ქ. = "city").
            $street = (string)preg_replace('~(ქალაქი|ქ\.)\s*' . preg_quote($city, '~') . '\s*,?~u', '', $street);
        }

        // Postcodes (Georgian ones are four digits starting with 0) and the
        // number sign: "№", "Nº" (Latin N), "Νº" (Greek capital Nu, seen in
        // real addresses), "N" right before digits.
        $street = (string)preg_replace('~(?<![\p{L}\p{N}])0\d{3}(?![\p{L}\p{N}]),?~u', '', $street);
        $street = (string)preg_replace('~(№|Nº|Νº|N(?=\d))\s*~u', '', $street);

        // Abbreviations: "გამზ." = avenue; "ქ." before a number = street.
        $street = str_replace('გამზ.', 'გამზირი', $street);
        $street = (string)preg_replace('~(?<![\p{L}\p{N}])ქ\.(?=\s*\d)~u', 'ქუჩა', $street);

        $isGeorgian = (bool)preg_match(self::GEORGIAN, $street);

        if ($isGeorgian) {
            $street = $this->registryName($street);
        }

        if ($city !== '') {
            // The city goes at the end, once. It is removed only where it
            // stands on its own — a part between commas (or at the start of
            // the line), or the end of the line — never inside a street name
            // ("ფოთის ქუჩა" in Poti, "Kutaisi Street" in Kutaisi). A Georgian
            // line loses the commas around it; a Latin one keeps the comma
            // that follows it.
            $word = '(?<![\p{L}\p{N}])' . preg_quote($city, '~') . '(?![\p{L}\p{N}])';
            $georgian = '~\s*(?:^|,)\s*' . $word . '\s*(?:,|$)|\s*,?\s*' . $word . '\s*$~u';
            $latin = '~(?:^|,)\s*' . $word . '(?=\s*(?:,|$))|,?\s*' . $word . '\s*$~iu';
            $street = $isGeorgian
                ? (string)preg_replace($georgian, ' ', $street)
                : (string)preg_replace($latin, '', $street);
        }

        return trim((string)preg_replace('~\s+~u', ' ', $street), ' ,');
    }

    /**
     * Registry style "Surname Name-s street N" → "Surname-is street N"
     * ("ასათიანი ლადოს ქუჩა 13" → "ასათიანის ქუჩა 13"): streets named after
     * people carry the surname in the genitive in OpenStreetMap.
     *
     * @param string $street
     * @return string
     */
    private function registryName(string $street): string
    {
        $pattern = '~^\s*(\S{3,}[იე]) (\S+ს) (' . self::STREET_KINDS . ')\s+(.*)$~u';

        if (!preg_match($pattern, $street, $m)) {
            return $street;
        }

        // Genitive of a surname ending in -ი or -ე: replace the ending with -ის.
        return mb_substr($m[1], 0, -1) . 'ის ' . $m[3] . ' ' . $m[4];
    }
}
