<?php

/**
 * Accepts a string value
 *
 * Returns a string formatted as follows:
 * (999) 999-9999
 * OR null if given value does not contain 10 numerical digits
 *
 * @param string|null $phone
 * @return null|string
 */
function formatPhone(string $phone=null) : ?string
{
    if(!$phone)
        return null;

    if(strlen($phone) !== 10 || (function($p){
            foreach(str_split($p) as $c)
                if(!is_numeric($c))
                    return true;

            return false;
        })($phone))
    {
        return null;
    }
    return strlen((string)$phone) !== 10 ? null :
        "(" . substr($phone, 0, 3 ). ") " .
        substr($phone, 3, 3) . "-" . substr($phone,6);
}

/**
 * Accept timestamp string such as:
 *
 * 2018-11-26 18:37:20
 *
 * And return simple date format m/d/Y
 *
 * @param string $date
 * @return string
 */
function simpleDateFormat(string $date) : string
{
    return date('m/d/Y', strtotime($date));
}

function simpleTimeFormat(string $date) : string
{
    return date('h:i a', strtotime($date));
}

/**
 * Echo the given $var to screen and end program execution
 * $var can be of any type
 */
function dad($var) : void
{
    echo '<pre>';
    echo var_export($var, true);
    echo '</pre>';
    die;
}

/**
 * Generate and return a valid guid v4
 *
 * @return string
 */
function uuid_create_v4() : string
{
    if (function_exists('com_create_guid') === true)
        return trim(com_create_guid(), '{}');

    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Obtain distance between the given lat/lng pairs. Keep in mind this is "The way the crow flies"
 * and not driving distance, which is generally WAAAAYYYY different.
 *
 * @param float $lat1
 * @param float $lng1
 * @param float $lat2
 * @param float $lng2
 * @return int
 */
function get_distance(float $lat1, float $lng1, float $lat2, float $lng2) : int
{
    return round(3959 * acos(cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            cos(deg2rad($lng2) - deg2rad($lng1)) + sin(deg2rad($lat1)) *
            sin(deg2rad($lat2))));
}

/**
 * Determine if the two arrays contain the same data
 *
 * @param array $a1
 * @param array $a2
 * @return bool
 */
function arrays_are_equal(array $a1, array $a2) : bool
{
    if(count($a1) !== count($a2))
        return false;

    sort($a1);
    sort($a2);
    for($i=0;$i<count($a1);$i++)
        if($a1[$i] != $a2[$i])
            return false;

    return true;
}

/**
 * Convert the given string $in to lowercase and remove all characters that
 * are not lowercase or uppercase letters or numbers. Useful for creating searchable
 * indexable strings
 *
 * @param string $in
 * @return string
 */
function normalize_string(string $in) : string
{
    return strtolower(preg_replace("/[^a-zA-Z0-9]+/", "", $in));
}

/**
 * Generate a temporary password with $chars length
 *
 * @param int $chars
 * @return string
 */
function generate_temp_password(int $chars = 8) : string
{
    $data = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcefghijklmnopqrstuvwxyz';
    return substr(str_shuffle($data), 0, $chars);
}

/**
 * Alias for ob_start() that makes better sense for view files
 *
 * @return bool
 */
function start_section() : bool
{
    return ob_start();
}

/**
 * Alias for ob_get_clean() that makes better sense for view files
 *
 * @return string
 */
function end_section() : string
{
    return ob_get_clean();
}

function echo_safe($val) : void
{
    echo htmlspecialchars($val);
}

/**
 * Return an array of all states where keys are 2 letter
 * abbreviations and values are whole names
 *
 * @return array
 */
function get_states() : array
{
    return [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'DC' => 'District Of Columbia',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming'
    ];
}