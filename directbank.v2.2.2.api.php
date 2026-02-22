<?php
/**
 * @doc: https://github.com/1C-Company/DirectBank/
 * @author Aleksey Petrishchev
 * @company Angels IT
 * @url https://angels-it.ru
 */
namespace AngelsIt;

require_once 'directbank.api.php';

class DirectBank1C extends DirectBank1CBase {
    public $api_version = '2.2.2';
}
