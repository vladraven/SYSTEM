# DATA_SOURCES — Official Source Contracts

**Version:** 2.0.0-json-native  
**Status:** canonical source-access contract  
**Verified:** 2026-08-13

This file freezes the production HTTP contracts used by MacroRisk.

## 1. Source hierarchy

### Tier 1 — Statistics Canada
Primary source for Canadian macroeconomic, demographic, labour, productivity and housing statistical series.

Official service:
`https://www150.statcan.gc.ca/t1/wds/rest/`

### Tier 1 — Bank of Canada
Primary source for monetary, financial-market, interest-rate, exchange-rate and commodity-price series.

Official service:
`https://www.bankofcanada.ca/valet/`

### Tier 1 — Government of Canada Open Government Portal
Primary source for Government of Canada datasets that are not more appropriately exposed through StatCan or BoC APIs.

Official CKAN API:
`https://open.canada.ca/data/en/api/3/action/`

No unofficial wrappers are permitted.

---

# 2. Native PHP HTTP implementation

All source adapters use ext-curl.

Canonical low-level GET:

```php
function httpGetJson(string $url): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: MacroRisk/2.0 (+https://github.com/vladraven/system)',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('SOURCE_TRANSPORT_ERROR: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("SOURCE_HTTP_ERROR: HTTP {$status}");
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException('SOURCE_SCHEMA_MISMATCH: JSON root is not an object/array.');
    }

    return $decoded;
}
```

Canonical JSON POST:

```php
function httpPostJson(string $url, array $payload): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: MacroRisk/2.0 (+https://github.com/vladraven/system)',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('SOURCE_TRANSPORT_ERROR: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("SOURCE_HTTP_ERROR: HTTP {$status}");
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}
```

---

# 3. Statistics Canada WDS

Statistics Canada WDS exposes 15 documented methods for data and metadata access. MacroRisk uses the vector APIs for discrete series, plus full-table downloads when a complete table is required.

## 3.1 Series metadata

Endpoint:

```text
POST https://www150.statcan.gc.ca/t1/wds/rest/getSeriesInfoFromVector
```

Payload:

```json
[
  {
    "vectorId": 32164132
  }
]
```

Native PHP:

```php
function statCanSeriesInfo(int $vectorId): array
{
    return httpPostJson(
        'https://www150.statcan.gc.ca/t1/wds/rest/getSeriesInfoFromVector',
        [
            ['vectorId' => $vectorId],
        ]
    );
}
```

## 3.2 Latest N periods

Endpoint:

```text
POST https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorsAndLatestNPeriods
```

Payload:

```json
[
  {
    "vectorId": 32164132,
    "latestN": 120
  }
]
```

Native PHP:

```php
function statCanLatestPeriods(int $vectorId, int $latestN): array
{
    return httpPostJson(
        'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorsAndLatestNPeriods',
        [
            [
                'vectorId' => $vectorId,
                'latestN' => $latestN,
            ],
        ]
    );
}
```

## 3.3 Bulk data by release-date range

Endpoint:

```text
POST https://www150.statcan.gc.ca/t1/wds/rest/getBulkVectorDataByRange
```

Payload:

```json
{
  "vectorIds": ["74804", "1"],
  "startDataPointReleaseDate": "2015-12-01T08:30",
  "endDataPointReleaseDate": "2018-03-31T19:00"
}
```

Native PHP:

```php
function statCanBulkByReleaseRange(
    array $vectorIds,
    string $startRelease,
    string $endRelease
): array {
    return httpPostJson(
        'https://www150.statcan.gc.ca/t1/wds/rest/getBulkVectorDataByRange',
        [
            'vectorIds' => array_map('strval', $vectorIds),
            'startDataPointReleaseDate' => $startRelease,
            'endDataPointReleaseDate' => $endRelease,
        ]
    );
}
```

## 3.4 Data by reference-period range

Endpoint:

```text
GET https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorByReferencePeriodRange
```

Parameters:

```text
vectorIds="1","2"
startRefPeriod=2016-01-01
endReferencePeriod=2017-01-01
```

Native PHP:

```php
function statCanReferenceRange(
    array $vectorIds,
    string $startReferencePeriod,
    string $endReferencePeriod
): array {
    $query = http_build_query([
        'vectorIds' => '"' . implode('","', array_map('strval', $vectorIds)) . '"',
        'startRefPeriod' => $startReferencePeriod,
        'endReferencePeriod' => $endReferencePeriod,
    ], '', '&', PHP_QUERY_RFC3986);

    return httpGetJson(
        'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorByReferencePeriodRange?' . $query
    );
}
```

## 3.5 Changed series

Endpoint:

```text
GET https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesList
```

Native PHP:

```php
function statCanChangedSeries(): array
{
    return httpGetJson(
        'https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesList'
    );
}
```

This is the preferred change-detection call for the ingestion scheduler.

## 3.6 Changed series data

Endpoint:

```text
POST https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesDataFromVector
```

Payload:

```json
[
  {
    "vectorId": 32164132
  }
]
```

Native PHP:

```php
function statCanChangedSeriesData(int $vectorId): array
{
    return httpPostJson(
        'https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesDataFromVector',
        [
            ['vectorId' => $vectorId],
        ]
    );
}
```

## 3.7 Cube metadata

Endpoint:

```text
POST https://www150.statcan.gc.ca/t1/wds/rest/getCubeMetadata
```

Payload:

```json
[
  {
    "productId": 35100003
  }
]
```

Native PHP:

```php
function statCanCubeMetadata(int $productId): array
{
    return httpPostJson(
        'https://www150.statcan.gc.ca/t1/wds/rest/getCubeMetadata',
        [
            ['productId' => $productId],
        ]
    );
}
```

## 3.8 Full table CSV

Endpoint:

```text
GET https://www150.statcan.gc.ca/t1/wds/rest/getFullTableDownloadCSV/{PID}/en
```

Example:

```text
https://www150.statcan.gc.ca/t1/wds/rest/getFullTableDownloadCSV/14100287/en
```

The response supplies the official static ZIP download URL.

Native PHP:

```php
function statCanFullTableCsvUrl(int $productId): string
{
    $response = httpGetJson(
        "https://www150.statcan.gc.ca/t1/wds/rest/getFullTableDownloadCSV/{$productId}/en"
    );

    if (!isset($response['object']) || !is_string($response['object'])) {
        throw new RuntimeException('SOURCE_SCHEMA_MISMATCH: missing StatCan CSV download URL.');
    }

    return $response['object'];
}
```

## 3.9 Full table SDMX

Endpoint:

```text
GET https://www150.statcan.gc.ca/t1/wds/rest/getFullTableDownloadSDMX/{PID}
```

Native PHP:

```php
function statCanFullTableSdmxUrl(int $productId): string
{
    $response = httpGetJson(
        "https://www150.statcan.gc.ca/t1/wds/rest/getFullTableDownloadSDMX/{$productId}"
    );

    if (!isset($response['object']) || !is_string($response['object'])) {
        throw new RuntimeException('SOURCE_SCHEMA_MISMATCH: missing StatCan SDMX download URL.');
    }

    return $response['object'];
}
```

## 3.10 StatCan parsing rules

The WDS datapoint contains fields including:
- refPer
- refPerRaw
- value
- decimals
- scalarFactorCode
- symbolCode
- statusCode
- securityLevelCode
- releaseTime
- frequencyCode

The parser must preserve these source fields before deriving the normalized observation.

The numeric value is stored as a decimal string. It must never be converted through PHP float.

---

# 4. Bank of Canada Valet API

The Valet API requires no registration and no access key for the public service.

Base URL:

```text
https://www.bankofcanada.ca/valet/
```

## 4.1 Observation series

Canonical route:

```text
GET https://www.bankofcanada.ca/valet/observations/{seriesName}/json
```

Example:

```text
https://www.bankofcanada.ca/valet/observations/V39079/json
```

Date-filtered request:

```text
https://www.bankofcanada.ca/valet/observations/V39079/json?start_date=2020-01-01&end_date=2026-08-13
```

Native PHP:

```php
function bankOfCanadaObservations(
    string $seriesName,
    ?string $startDate = null,
    ?string $endDate = null
): array {
    $url = 'https://www.bankofcanada.ca/valet/observations/'
        . rawurlencode($seriesName)
        . '/json';

    $params = [];

    if ($startDate !== null) {
        $params['start_date'] = $startDate;
    }

    if ($endDate !== null) {
        $params['end_date'] = $endDate;
    }

    if ($params !== []) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    return httpGetJson($url);
}
```

## 4.2 Multiple series

When the Valet endpoint supports a series list/group contract, the adapter may use the documented `seriesName` or `groupName` parameters. The adapter must preserve the exact returned series identity and must not infer a series from display names.

## 4.3 Data discovery

Series discovery must use the official Valet endpoint documentation and Bank of Canada series/group listings.

The source registry stores:
- source_key;
- series_name;
- official title;
- frequency;
- unit;
- first observation;
- source URL;
- documentation URL;
- license/terms status.

## 4.4 Cache policy

Bank of Canada data that changes only daily must be cached in JSON.

Repeated requests for an unchanged daily series are forbidden unless a forced refresh is explicitly requested.

## 4.5 Source validation

The adapter must validate:
- HTTP status;
- JSON root;
- series identifier;
- observation structure;
- observation date;
- numeric value;
- source metadata when present.

---

# 5. Government of Canada Open Government / CKAN

Official portal:

```text
https://open.canada.ca/data/en/
```

Action API:

```text
https://open.canada.ca/data/en/api/3/action/
```

## 5.1 Dataset search

```text
GET https://open.canada.ca/data/en/api/3/action/package_search?q=business%20insolvencies
```

Native PHP:

```php
function openGovernmentPackageSearch(string $query): array
{
    $url = 'https://open.canada.ca/data/en/api/3/action/package_search?'
        . http_build_query(
            ['q' => $query],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    return httpGetJson($url);
}
```

## 5.2 Dataset metadata

```text
GET https://open.canada.ca/data/en/api/3/action/package_show?id={package_id}
```

Native PHP:

```php
function openGovernmentPackageShow(string $packageId): array
{
    $url = 'https://open.canada.ca/data/en/api/3/action/package_show?'
        . http_build_query(
            ['id' => $packageId],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    return httpGetJson($url);
}
```

## 5.3 DataStore query

```text
GET https://open.canada.ca/data/en/api/3/action/datastore_search
```

Parameters:
- resource_id
- limit
- offset
- q
- filters
- sort

Native PHP:

```php
function openGovernmentDataStoreSearch(
    string $resourceId,
    int $limit = 100,
    int $offset = 0,
    ?string $query = null
): array {
    $params = [
        'resource_id' => $resourceId,
        'limit' => $limit,
        'offset' => $offset,
    ];

    if ($query !== null) {
        $params['q'] = $query;
    }

    $url = 'https://open.canada.ca/data/en/api/3/action/datastore_search?'
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return httpGetJson($url);
}
```

## 5.4 OSB/business insolvency policy

Business insolvency data must be discovered and consumed through the official Government of Canada Open Government Portal / CKAN resources.

A hard-coded resource ID may be used only after the dataset metadata has been validated and recorded in `storage/config/sources.json`.

The application must not silently substitute a different dataset when a configured resource disappears.

---

# 6. Source registry

Every production series is registered in:

```text
storage/config/sources.json
```

Example:

```json
{
  "schema_version": 1,
  "sources": {
    "statcan_wds": {
      "provider": "Statistics Canada",
      "base_url": "https://www150.statcan.gc.ca/t1/wds/rest/",
      "license_status": "public_open"
    },
    "bank_of_canada": {
      "provider": "Bank of Canada",
      "base_url": "https://www.bankofcanada.ca/valet/",
      "license_status": "public_open"
    },
    "open_government": {
      "provider": "Government of Canada",
      "base_url": "https://open.canada.ca/data/en/api/3/action/",
      "license_status": "public_open"
    }
  }
}
```

A production indicator cannot reference an unregistered source.

---

# 7. Prohibited source behavior

Never:
- scrape HTML when an official API exists;
- use unofficial wrappers;
- fabricate missing observations;
- replace missing observations with zero;
- silently change source series IDs;
- silently change table/vector mappings;
- use CMHC/CREA as default where StatCan has the required official equivalent;
- treat model output as source observation.

# 8. Source changes

If an official endpoint changes:
1. mark the source mapping stale;
2. preserve the previous raw data;
3. record the HTTP/schema failure;
4. update DATA_SOURCES.md;
5. update the adapter;
6. add/update fixtures;
7. run the source contract tests;
8. only then re-enable production ingestion.
