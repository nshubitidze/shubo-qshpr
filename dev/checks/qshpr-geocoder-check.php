<?php
// Checks for address geocoding: AddressNormalizer, NominatimProvider, Geocoder.
//  1. config.xml defaults, the admin geocoder list
//  2. normalizer: one case per rule, then 228 Georgian addresses (Wikidata,
//     CC0) against the queries a reference normalizer produced
//     (fixtures/normalizer-cases.jsonl)
//  3. NominatimProvider over stubbed HTTP: request shape, precision, city, failures
//  4. Geocoder over a stub provider: precision gate, city check, cache, failures
//  5. LIVE, public Nominatim, 8 addresses with known coordinates, 1.1 s apart
//  6. log assertions on var/log/shubo_qshpr.log (this run's lines only)
//
// Run inside phpfpm from the Magento root: php <module-dir>/dev/checks/qshpr-geocoder-check.php

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Phrase;
use Shubo\Qshpr\Exception\GeocodingException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Config\Source\Geocoder as GeocoderSource;
use Shubo\Qshpr\Model\Geocoding\Address;
use Shubo\Qshpr\Model\Geocoding\AddressNormalizer;
use Shubo\Qshpr\Model\Geocoding\Geocoder;
use Shubo\Qshpr\Model\Geocoding\Location;
use Shubo\Qshpr\Model\Geocoding\NominatimProvider;
use Shubo\Qshpr\Model\Geocoding\Precision;
use Shubo\Qshpr\Model\Geocoding\ProviderInterface;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

require __DIR__ . '/_stubs.php';

$logFile = BP . '/var/log/shubo_qshpr.log';
$logOffset = is_file($logFile) ? (int)filesize($logFile) : 0;

const P = 'carriers/shuboqshpr/';
const GEORGIAN_CITY = ['Tbilisi' => 'თბილისი', 'Batumi' => 'ბათუმი', 'Kutaisi' => 'ქუთაისი', 'Rustavi' => 'რუსთავი'];

$realScope = $om->get(ScopeConfigInterface::class);

/** Distance in metres between two points (haversine). */
function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

    return 2 * 6371000 * asin(sqrt($a));
}

/** Provider stand-in: returns queued answers (a Location, null, or an exception to throw) and counts calls. */
function stubProvider(array $answers): ProviderInterface
{
    return new class ($answers) implements ProviderInterface {
        public int $calls = 0;
        public array $queries = [];

        public function __construct(private array $answers)
        {
        }

        public function search(string $query, Address $address, ?int $storeId): ?Location
        {
            $this->calls++;
            $this->queries[] = $query;
            $answer = array_shift($this->answers);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        }
    };
}

function geocoderWith($om, ProviderInterface $provider, $cache, array $overrides = []): Geocoder
{
    return $om->create(Geocoder::class, [
        'providers' => ['stub' => ['label' => 'Stub geocoder', 'model' => $provider]],
        'cache' => $cache,
        'config' => $om->create(Config::class, [
            'scopeConfig' => overrideScope(
                $overrides + [P . 'geocoding/geocoder' => 'stub', P . 'debug' => '0'],
                $om->get(ScopeConfigInterface::class)
            ),
        ]),
    ]);
}

function house(string $city, float $lat = 41.6985, float $lng = 44.7978): Location
{
    return new Location($lat, $lng, Precision::House, $city);
}

// --- 1. Defaults and the admin list ---
check('config.xml: geocoder default is nominatim', (string)$realScope->getValue(P . 'geocoding/geocoder') === 'nominatim');
check('config.xml: Nominatim public server by default',
    (string)$realScope->getValue(P . 'geocoding/nominatim_url') === 'https://nominatim.openstreetmap.org');
$urlConfig = static fn (array $values): Config => $om->create(Config::class, [
    'scopeConfig' => overrideScope($values, $om->get(ScopeConfigInterface::class)),
]);
check('config: geocoder URL is the selected geocoder\'s <code>_url',
    $urlConfig([P . 'geocoding/geocoder' => 'nominatim', P . 'geocoding/nominatim_url' => ' https://osm.example/ '])
        ->getGeocoderUrl() === 'https://osm.example');
check('config: another geocoder reads its own <code>_url',
    $urlConfig([P . 'geocoding/geocoder' => 'other', P . 'geocoding/other_url' => 'https://other.example/'])
        ->getGeocoderUrl() === 'https://other.example');
check('config: geocoder without a URL setting → empty',
    $urlConfig([P . 'geocoding/geocoder' => 'nosuch'])->getGeocoderUrl() === '');
$options = $om->get(GeocoderSource::class)->toOptionArray();
check('admin list offers Nominatim (from di.xml)', count($options) >= 1
    && $options[0]['value'] === 'nominatim' && (string)$options[0]['label'] === 'Nominatim (OpenStreetMap)');

// --- 2. Normalizer ---
$normalizer = $om->get(AddressNormalizer::class);
$n = static fn (string $street, string $city): string => $normalizer->normalize(new Address($street, $city, 'GE'));
$cases = [
    ['ქ. თბილისი, ასათიანი ლადოს ქუჩა №13, 0177', 'თბილისი', 'ასათიანის ქუჩა 13, თბილისი', 'registry style + city prefix + № + postcode'],
    ['რუსთაველის გამზ. 12', 'თბილისი', 'რუსთაველის გამზირი 12, თბილისი', 'გამზ. → გამზირი'],
    ['ჭავჭავაძის ქ. 5', 'თბილისი', 'ჭავჭავაძის ქუჩა 5, თბილისი', 'ქ. before a number → ქუჩა'],
    ['ბარნოვის ქუჩა 3 / მელიქიშვილის ქუჩა 5', 'თბილისი', 'ბარნოვის ქუჩა 3, თბილისი', 'corner address keeps the first street'],
    ['ფალიაშვილის ქუჩა 12/4', 'თბილისი', 'ფალიაშვილის ქუჩა 12/4, თბილისი', 'house number with a slash is kept'],
    ['5 Rustaveli Avenue, Tbilisi 0108', 'Tbilisi', '5 Rustaveli Avenue, Tbilisi', 'Latin: city and postcode moved/removed'],
    ['  Rustaveli   Ave 5 ', 'tbilisi', 'Rustaveli Ave 5, tbilisi', 'spaces collapsed'],
    ['ფოთის ქუჩა 5', 'ფოთი', 'ფოთის ქუჩა 5, ფოთი', 'the city inside a Georgian street name is kept'],
    ['Kutaisi Street 7', 'Kutaisi', 'Kutaisi Street 7, Kutaisi', 'the city inside a Latin street name is kept'],
    ['თბილისი, ჭავჭავაძის ქუჩა 5', 'თბილისი', 'ჭავჭავაძის ქუჩა 5, თბილისი', 'the city as the first part is removed'],
    ['Rustaveli Ave 5 Tbilisi', 'Tbilisi', 'Rustaveli Ave 5, Tbilisi', 'the city at the end without a comma is removed'],
    ['ჭავჭავაძის ქუჩა Nº 5', 'თბილისი', 'ჭავჭავაძის ქუჩა 5, თბილისი', 'Latin "Nº" removed'],
    ['ჭავჭავაძის ქუჩა Νº 5', 'თბილისი', 'ჭავჭავაძის ქუჩა 5, თბილისი', 'Greek "Νº" removed'],
];
foreach ($cases as [$street, $city, $expected, $name]) {
    $got = $n($street, $city);
    check("normalizer: $name", $got === $expected, $got);
}

$testSet = __DIR__ . '/fixtures/normalizer-cases.jsonl';
$same = 0;
$diffs = [];
foreach (file($testSet, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $row = json_decode($line, true);
    $city = $row['lang'] === 'ka' ? GEORGIAN_CITY[$row['city']] : $row['city'];
    $got = $n($row['addr'], $city);
    if ($got === $row['q']) {
        $same++;
    } else {
        $diffs[] = $row['addr'] . ' → ' . $got . ' (expected: ' . $row['q'] . ')';
    }
}
check('normalizer: matches the reference queries on the 228-address set',
    $diffs === [], "$same same" . ($diffs !== [] ? '; first diffs: ' . implode(' | ', array_slice($diffs, 0, 3)) : ''));

// --- 3. NominatimProvider over stubbed HTTP ---
function nominatim($om, array $queue, $factory = null): array
{
    $factory ??= fakeCurlFactory($queue);

    return [$om->create(NominatimProvider::class, ['curlFactory' => $factory]), $factory];
}

$houseBody = json_encode([[
    'lat' => '41.6985060', 'lon' => '44.7978122', 'place_rank' => 30, 'addresstype' => 'place',
    'address' => ['house_number' => '12', 'road' => 'Shota Rustaveli Avenue', 'city' => 'Tbilisi', 'country_code' => 'ge'],
]]);
[$nom, $factory] = nominatim($om, [[200, $houseBody]]);
$loc = $nom->search('Rustaveli Avenue 12, Tbilisi', new Address('Rustaveli Avenue 12', 'Tbilisi', 'GE'), null);
check('nominatim: rank 30 → House with coordinates and city',
    $loc !== null && $loc->precision === Precision::House && abs($loc->latitude - 41.698506) < 1e-6
        && $loc->city === 'Tbilisi' && $loc->provider === '');
$req = $factory->made[0];
parse_str((string)parse_url($req->uri, PHP_URL_QUERY), $params);
check('nominatim: GET /search on the configured server',
    str_starts_with($req->uri, 'https://nominatim.openstreetmap.org/search?') && $req->method === 'GET');
check('nominatim: query, jsonv2, address details, one result, country filter',
    $params['q'] === 'Rustaveli Avenue 12, Tbilisi' && $params['format'] === 'jsonv2'
        && $params['addressdetails'] === '1' && $params['limit'] === '1' && $params['countrycodes'] === 'ge');
check('nominatim: Latin city → English answer', $params['accept-language'] === 'en');
check('nominatim: identifying User-Agent', str_starts_with($req->sentHeaders()['User-Agent'] ?? '', 'Shubo_Qshpr Magento module ('));
check('nominatim: 5 s timeout (di.xml)', $req->sentTimeout() === 5);
$slowFactory = fakeCurlFactory([[200, '[]']]);
$om->create(NominatimProvider::class, ['curlFactory' => $slowFactory, 'timeoutSeconds' => 12])
    ->search('q', new Address('s', 'Tbilisi', 'GE'), null);
check('nominatim: timeout set by a di.xml argument', $slowFactory->made[0]->sentTimeout() === 12);

[$nom, $factory] = nominatim($om, [[200, '[]']]);
$nom->search('ლესელიძის ქუჩა 47, თბილისი', new Address('ლესელიძის ქუჩა 47', 'თბილისი', 'GE'), null);
parse_str((string)parse_url($factory->made[0]->uri, PHP_URL_QUERY), $params);
check('nominatim: Georgian city → Georgian answer', $params['accept-language'] === 'ka');

$rank = static function ($om, int $rank, array $address = ['city' => 'Tbilisi']): ?Location {
    [$nom] = nominatim($om, [[200, json_encode([['lat' => '41.7', 'lon' => '44.8', 'place_rank' => $rank, 'address' => $address]])]]);

    return $nom->search('q', new Address('s', 'Tbilisi', 'GE'), null);
};
check('nominatim: rank 26 → Street', $rank($om, 26)?->precision === Precision::Street);
check('nominatim: rank 16 → Area', $rank($om, 16)?->precision === Precision::Area);
check('nominatim: town used when there is no city', $rank($om, 30, ['town' => 'Mtskheta'])?->city === 'Mtskheta');
check('nominatim: no settlement → empty city', $rank($om, 30, ['road' => 'x'])?->city === '');

[$nom] = nominatim($om, [[200, '[]']]);
check('nominatim: empty answer → null', $nom->search('q', new Address('s', 'Tbilisi', 'GE'), null) === null);

foreach ([[500, 'oops', 'HTTP 500'], [200, 'not-json{', 'unreadable'], [200, '"a string"', 'unreadable']] as [$status, $body, $needle]) {
    [$nom] = nominatim($om, [[$status, $body]]);
    try {
        $nom->search('q', new Address('s', 'Tbilisi', 'GE'), null);
        check("nominatim: $needle → GeocodingException", false, 'no exception');
    } catch (GeocodingException $e) {
        check("nominatim: $needle → GeocodingException", str_contains($e->getMessage(), $needle), $e->getMessage());
    }
}

// --- 4. Geocoder over a stub provider ---
$cache = memoryCache();
$stub = stubProvider([house('Tbilisi')]);
$geo = geocoderWith($om, $stub, $cache);
$address = new Address('Rustaveli Avenue 12', 'Tbilisi', 'GE');
$loc = $geo->locate($address);
check('geocoder: house-level answer in the typed city → location, named after its di.xml key',
    $loc !== null && $loc->city === 'Tbilisi' && $loc->provider === 'stub');
$labels = $om->create(Geocoder::class, ['providers' => [
    'broken' => ['label' => 'No model'],
    'stub' => ['label' => 'Stub geocoder', 'model' => $stub],
]])->getProviderLabels();
check('geocoder: admin labels from di.xml; an entry without a model is skipped',
    array_keys($labels) === ['stub'] && (string)$labels['stub'] === 'Stub geocoder');
check('geocoder: provider got the normalized query', $stub->queries === ['Rustaveli Avenue 12, Tbilisi']);
$plainStub = stubProvider([house('Tbilisi')]);
geocoderWith($om, $plainStub, memoryCache(), [P . 'geocoding/address_clean_up' => '0'])
    ->locate(new Address('ქ. თბილისი, რუსთაველის გამზ. №12', 'თბილისი', 'GE'));
check('geocoder: clean-up switched off → the address as typed ("<street line>, <city>")',
    $plainStub->queries === ['ქ. თბილისი, რუსთაველის გამზ. №12, თბილისი'], json_encode($plainStub->queries));
$key = array_key_first($cache->data);
check('geocoder: found cached for 30 days, tagged', $cache->lifetimes[$key] === 2592000
    && in_array('SHUBO_QSHPR_GEOCODE', $cache->tags[$key], true));
check('geocoder: cache key and value hold no address text',
    !str_contains($key, 'Rustaveli') && !str_contains((string)$cache->data[$key], 'Rustaveli'));
$again = $geo->locate($address);
check('geocoder: second lookup served from cache (no provider call)', $stub->calls === 1 && $again == $loc);
check('geocoder: city match ignores case and spaces',
    geocoderWith($om, stubProvider([house('Tbilisi')]), memoryCache())->locate(new Address('x 1', ' tbilisi ', 'GE')) !== null);

$cache = memoryCache();
$stub = stubProvider([house('Tbilisi')]);
$geo = geocoderWith($om, $stub, $cache);
check('geocoder: city check — house found in another city → null',
    $geo->locate(new Address('Vazha-Pshavela Street 5', 'Rustavi', 'GE')) === null);
check('geocoder: city-check reject still cached (no second call)',
    $geo->locate(new Address('Vazha-Pshavela Street 5', 'Rustavi', 'GE')) === null && $stub->calls === 1);

check('geocoder: street-level answer → null',
    geocoderWith($om, stubProvider([new Location(41.7, 44.8, Precision::Street, 'Tbilisi', 'stub')]), memoryCache())
        ->locate($address) === null);
check('geocoder: answer without a city → null',
    geocoderWith($om, stubProvider([house('')]), memoryCache())->locate($address) === null);

$cache = memoryCache();
$stub = stubProvider([null]);
$geo = geocoderWith($om, $stub, $cache);
check('geocoder: not found → null', $geo->locate($address) === null);
$key = array_key_first($cache->data);
check('geocoder: not found cached for a day', $cache->data[$key] === 'none' && $cache->lifetimes[$key] === 86400);
$geo->locate($address);
check('geocoder: cached "not found" answered without a provider call', $stub->calls === 1);

$cache = memoryCache();
$stub = stubProvider([new GeocodingException(__('Nominatim returned HTTP 503.')), house('Tbilisi')]);
$geo = geocoderWith($om, $stub, $cache);
check('geocoder: provider failure → null', $geo->locate($address) === null);
check('geocoder: failure not cached', $cache->data === []);
check('geocoder: next attempt asks again and succeeds', $geo->locate($address) !== null && $stub->calls === 2);

$stub = stubProvider([house('Tbilisi')]);
$geo = geocoderWith($om, $stub, memoryCache());
check('geocoder: empty street → null, no provider call',
    $geo->locate(new Address('  ', 'Tbilisi', 'GE')) === null && $stub->calls === 0);
check('geocoder: empty city → null, no provider call',
    $geo->locate(new Address('Rustaveli 1', '', 'GE')) === null && $stub->calls === 0);
check('geocoder: unknown geocoder code in config → null',
    geocoderWith($om, stubProvider([]), memoryCache(), [P . 'geocoding/geocoder' => 'nosuch'])->locate($address) === null);

// --- 5. LIVE: public Nominatim ---
// Addresses whose position is known (Wikidata) and which Nominatim found at
// building level within 40 m in the 2026-09-29 test.
$fixtures = [
    ['ლესელიძე კონსტანტინეს ქუჩა 47, თბილისი', 'თბილისი', 41.69, 44.80727],
    ['ბოტანიკურის ქუჩა 32, თბილისი', 'თბილისი', 41.6872, 44.8102],
    ['ია კარგარეთელის ქუჩა 6, თბილისი', 'თბილისი', 41.715799, 44.794258],
    ['ლესელიძე კონსტანტინეს ქუჩა 41, თბილისი', 'თბილისი', 41.69068, 44.806408],
    ['6 Ia Kargareteli Street, Tbilisi', 'Tbilisi', 41.715799, 44.794258],
    ['5 Rustaveli Avenue, Tbilisi 0108', 'Tbilisi', 41.696767, 44.799816],
    ['ქ. ბათუმი, ვაჟა-ფშაველას ქ. №33, ბათუმი', 'ბათუმი', 41.64735, 41.63448],
    ['ქუთაისი, დავით აღმაშენებლის მოედანი №1, ქუთაისი', 'ქუთაისი', 42.272431, 42.705521],
];
// Debug off: the log assertions below are about normal logging, whatever the store has set.
$live = $om->create(Geocoder::class, [
    'config' => $om->create(Config::class, [
        'scopeConfig' => overrideScope([P . 'debug' => '0'], $om->get(ScopeConfigInterface::class)),
    ]),
    'cache' => memoryCache(),
]);
$within = 0;
$far = [];
$missed = [];
foreach ($fixtures as $i => [$street, $city, $lat, $lng]) {
    if ($i > 0) {
        usleep(1100000);  // public server policy: at most 1 request per second
    }
    $loc = $live->locate(new Address($street, $city, 'GE'));
    if ($loc === null) {
        $missed[] = $street;
        continue;
    }
    $d = metres($lat, $lng, $loc->latitude, $loc->longitude);
    $d <= 250 ? $within++ : $far[] = sprintf('%s (%.0f m)', $street, $d);
}
check('live: at least 7 of 8 known addresses located within 250 m', $within >= 7,
    "$within within 250 m; missed: " . (implode('; ', $missed) ?: 'none'));
check('live: no located address more than 250 m off', $far === [], implode('; ', $far));

// --- 6. Log ---
$log = substr((string)file_get_contents($logFile), $logOffset);
check('log: one line per lookup', str_contains($log, 'geocode nominatim in ') && str_contains($log, 'geocode stub in '));
check('log: provider failure logged', str_contains($log, 'geocode stub failed after'));
check('log: unknown geocoder logged', str_contains($log, 'geocoder "nosuch" is not available'));
check('log: no address text without debug', !str_contains($log, 'Rustaveli') && !str_contains($log, 'ლესელიძ'));

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
