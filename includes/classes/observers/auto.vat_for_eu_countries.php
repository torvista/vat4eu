<?php
// -----
// Part of the VAT4EU plugin by Cindy Merkin a.k.a. lat9 (cindy@vinosdefrutastropicales.com)
// Copyright (c) 2017 Vinos de Frutas Tropicales
//
class zcObserverVatForEuCountries extends base 
{
    private $isEnabled = false;
    private $vatValidated = false;
    private $vatIsRefundable = false;
    private $vatNumber = '';
    private $vatNumberStatus = 0;
    private $vatGathered = false;
    private $addressFormatCount = 0;
    private $vatCountries = array();
    public  $debug = array();
    
    // -----
    // On construction, this auto-loaded observer checks to see that the plugin is enabled and, if so:
    //
    // - Register for the notifications pertinent to the plugin's processing.
    // - Create an instance of the "VAT Validation" class, for possible future use.
    // - Set initial values base on the plugin's database configuration.
    //
    public function __construct() 
    {
        // -----
        // If the plugin is enabled ...
        //
        if (defined('VAT4EU_ENABLED') && VAT4EU_ENABLED == 'true') {
            $this->isEnabled = true;
            $this->attach(
                $this, 
                array(
                    //- From /includes/classes/order.php
                    'NOTIFY_ORDER_AFTER_QUERY',                         //- Reconstructing a previously-placed order
                    'NOTIFY_ORDER_DURING_CREATE_ADDED_ORDER_HEADER',    //- Creating an order, after the main orders-table entry has been created
                    
                    //- From /includes/modules/create_account.php
                    'NOTIFY_CREATE_ACCOUNT_VALIDATION_CHECK',                   //- Allows us to check/validate any supplied VAT Number
                    'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_ADDRESS_BOOK_RECORD',   //- Indicates that the account was created successfully
                    
                    //- From /includes/modules/checkout_new_address.php
                    'NOTIFY_MODULE_CHECKOUT_NEW_ADDRESS_VALIDATION',        //- Allows us to check/validate any supplied VAT Number
                    'NOTIFY_MODULE_CHECKOUT_ADDED_ADDRESS_BOOK_RECORD',     //- Indicates that the record was created successfully
                    
                    //- From /includes/modules/pages/address_book_process/header_php.php
                    'NOTIFY_ADDRESS_BOOK_PROCESS_VALIDATION',                   //- Allows us to check/validate any supplied VAT Number
                    'NOTIFY_MODULE_ADDRESS_BOOK_UPDATED_ADDRESS_BOOK_RECORD',   //- Indicates that an address-record was just updated
                    'NOTIFY_MODULE_ADDRESS_BOOK_ADDED_ADDRESS_BOOK_RECORD',     //- Indicates that an address-record was just created
                    
                    //- From /includes/modules/pages/shopping_cart/header_php.php
                    'NOTIFY_HEADER_END_SHOPPING_CART',          //- End of the "standard" page's processing
                    
                    //- From /includes/functions/functions_customers.php
                    'NOTIFY_END_ZEN_ADDRESS_FORMAT',            //- Issued at the end of the zen_address_format function
                    'NOTIFY_ZEN_ADDRESS_LABEL',                 //- Issued during the zen_address_label function
                )
            );

            $this->vatCountries = explode(',', str_replace(' ', '', VAT4EU_EU_COUNTRIES));
        }
    }

    // -----
    // This function receives control when one of its attached notifications is "fired".
    //
    public function update(&$class, $eventID, $p1, &$p2, &$p3, &$p4, &$p5) {
        switch ($eventID) {
            // -----
            // Issued by the order-class after completing its base reconstruction of a 
            // previously-placed order.  We'll tack any "VAT Number" recorded in the order
            // into the class' to-be-returned array.
            //
            // Entry:
            //  $class ... A reference to an order-class object.
            //  $p1 ...... An empty array
            //  $p2 ...... The orders_id being queried.
            //
            case 'NOTIFY_ORDER_AFTER_QUERY':
                $vat_info = $GLOBALS['db']->Execute(
                    "SELECT billing_vat_number, billing_vat_validated
                       FROM " . TABLE_ORDERS . "
                      WHERE orders_id = " . (int)$p2 . "
                      LIMIT 1"
                );
                if (!$vat_info->EOF) {
                    $class->billing['billing_vat_number'] = $vat_info->fields['billing_vat_number'];
                    $class->billing['billing_vat_validated'] = $vat_info->fields['billing_vat_validated'];
                }
                break;
                
            // -----
            // Issued by the order-class after completing its base construction of an order's
            // information.
            //
            // Entry:
            //  $class ... A reference to an order-class object.
            //  $p1 ...... An associative array containing the information written to the orders table.
            //  $p2 ...... The order's id.
            //
            case 'NOTIFY_ORDER_DURING_CREATE_ADDED_ORDER_HEADER':
                $this->checkVatIsRefundable();
                if ($this->vatNumber != '') {
                    $GLOBALS['db']->Execute(
                        "UPDATE " . TABLE_ORDERS . "
                            SET billing_vat_number = '" . zen_db_prepare_input($this->vatNumber) . "',
                                billing_vat_validated = " . $this->vatValidated . "
                          WHERE orders_id = " . (int)$p2 . "
                          LIMIT 1"
                    );
                }
                break;
                
            // -----
            // Issued during the create-account, address-book or checkout-new-address processing, gives us a chance to validate (if
            // required) the customer's entered VAT Number.
            //
            // Entry:
            //  $p2 ... A reference to the module's $error variable.
            //
            case 'NOTIFY_CREATE_ACCOUNT_VALIDATION_CHECK':
                $message_location = 'create_account';               //- Fall through ...
            case 'NOTIFY_MODULE_CHECKOUT_NEW_ADDRESS_VALIDATION':
                if (!isset($message_location)) {
                    $message_location = 'checkout_address';
                }                                                   //- Fall through ...
            case 'NOTIFY_ADDRESS_BOOK_PROCESS_VALIDATION':
                if (!isset($message_location)) {
                    $message_location = 'addressbook';
                }
                if (!$this->validateVatNumber($message_location)) {
                    $p2 = true;
                }
                break;
                
            // -----
            // Issued during the create-account, address-book or checkout-new-address processing, indicates that an address record
            // has been created/updated and gives us a chance to record the customer's VAT Number.
            //
            // Entry:
            //  $p1 ... An associative array that contains the address-book entry's default data.
            //
            case 'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_ADDRESS_BOOK_RECORD':  //- Fall through ...
            case 'NOTIFY_MODULE_CHECKOUT_ADDED_ADDRESS_BOOK_RECORD':        //- Fall through ...
            case 'NOTIFY_MODULE_ADDRESS_BOOK_UPDATED_ADDRESS_BOOK_RECORD':  //- Fall through ...
            case 'NOTIFY_MODULE_ADDRESS_BOOK_ADDED_ADDRESS_BOOK_RECORD':
                $address_book_id = ($eventID == 'NOTIFY_MODULE_ADDRESS_BOOK_UPDATED_ADDRESS_BOOK_RECORD') ? $p1['address_book_id'] : $p1['address_id'];
                $vat_number = zen_db_prepare_input($_POST['vat_number']);
                $GLOBALS['db']->Execute(
                    "UPDATE " . TABLE_ADDRESS_BOOK . "
                        SET entry_vat_number = '$vat_number',
                            entry_vat_validated = " . $this->vatNumberStatus . "
                      WHERE address_book_id = $address_book_id
                        AND customers_id = " . (int)$_SESSION['customer_id'] . "
                      LIMIT 1"
                );
                break;
                
            // -----
            // Issued by the "shopping_cart" page's header when it's completed its processing.  Allows us to
            // determine whether a currently-logged-in customer qualifies for a VAT refund.
            //
            case 'NOTIFY_HEADER_END_SHOPPING_CART':
                if ($this->vatIsRefundable && isset($GLOBALS['products']) && is_array($GLOBALS['products'])) {
                    $products_tax = 0;
                    $currency_decimal_places = $GLOBALS['currencies']->get_decimal_places($_SESSION['currency']);
                    foreach ($GLOBALS['products'] as $current_product) {
                        $current_tax = zen_calculate_tax($current_product['final_price'], zen_get_tax_rate($current_product['tax_class_id']));
                        $products_tax += $current_product['quantity'] * zen_round($current_tax, $currency_decimal_places);
                    }
                    $this->vatRefund = $products_tax;
                    $GLOBALS['cartShowTotal'] .= '<br /><span class="vat-refund-label">' . VAT4EU_TEXT_VAT_REFUND . '</span><span class="vat-refund_amt">' . $GLOBALS['currencies']->format($products_tax) . '</span>';
                }
                break;
                
            // -----
            // Issued at the end of the zen_address_format function, just prior to return.  Gives us a chance to
            // insert the "VAT Number" value, if indicated.
            //
            // On completion of the address-formatting, reset the flag that indicates that the VAT information
            // has been "gathered" to allow mixed zen_address_format/zen_address_label calls to operate properly.
            //
            // This notification includes the following variables:
            //
            // $p1 ... (r/o) An associative array containing the various elements of the to-be-formatted address
            // $p2 ... (r/w) A reference to the functions return value, possibly modified by this processing.
            //
            case 'NOTIFY_END_ZEN_ADDRESS_FORMAT':
                $this->checkVatIsRefundable();
                $updated_address = $this->formatAddress($p1, $p2);
                if ($updated_address !== false) {
                    $p2 = $updated_address;
                }
                $this->debug($eventID . ': ' . var_export($this, true));
                $this->vatGathered = false;
                break;
                
            // -----
            // Issued by zen_address_label after gathering the address fields for a specified customer's address.  Gives this plugin
            // the opportunity to capture any "VAT Number" associated with the associated address ... for use by
            // the function's subsequent call to zen_address_format.
            //
            // Upon completion, the 'vatGathered' flag is set to let the next access to the zen_address_format processing
            // "know" that the VAT information has already been set for the next access.
            //
            // This notification includes the following variables:
            //
            // $p1 ... (n/a)
            // $p2 ... The customers_id value
            // $p3 ... The address_book_id value
            //
            case 'NOTIFY_ZEN_ADDRESS_LABEL':
                $this->checkVatIsRefundable($p2, $p3);
                $this->vatGathered = true;
                break;
                
            default:
                break;
        }
    }
    
    public function isVatRefundable()
    {
        return $this->checkVatIsRefundable();
    }
    
    public function getCountryIsoCode2($countries_id)
    {
        $check = $GLOBALS['db']->Execute(
            "SELECT countries_iso_code_2
               FROM " . TABLE_COUNTRIES . "
              WHERE countries_id = " . (int)$countries_id . "
              LIMIT 1"
        );
        return ($check->EOF) ? 'Unknown' : $check->fields['countries_iso_code_2'];
    }
    
    // -----
    // This function, called for pages that make modifications to an account's
    // VAT Number, provides basic validation for that number.
    //
    protected function validateVatNumber($message_location)
    {
        $vat_ok = true;
        $GLOBALS['vat_number'] = $vat_number = zen_db_prepare_input($_POST['vat_number']);
        $vat_number_length = strlen($vat_number);
        $this->vatNumberStatus = 0;     //- Unvalidated
        if ($vat_number != '') {
            $vat_ok = false;
            if (VAT4EU_MIN_LENGTH != '0' && $vat_number_length < VAT4EU_MIN_LENGTH) {
                $GLOBALS['messageStack']->add($message_location, VAT4EU_ENTRY_VAT_MIN_ERROR, 'error');
            } else {
                $countries_id = $_POST['zone_country_id'];
                $country_iso_code_2 = $this->getCountryIsoCode2($countries_id);
                if (strpos(strtoupper($vat_number), $country_iso_code_2) !== 0) {
                    $GLOBALS['messageStack']->add($message_location, sprintf(VAT4EU_ENTRY_VAT_PREFIX_INVALID, $country_iso_code_2, zen_get_country_name($countries_id)), 'error');
                } else {
                    $vat_ok = true;
                    if (VAT4EU_VALIDATION == 'Admin') {
                        $this->vatNumberStatus = 2;     //- Pending (admin validation)
                    } else {
                        if (!class_exists('VatValidation')) {
                            require DIR_WS_CLASSES . 'VatValidation.php';
                        }
                        $validation = new VatValidation();
                        if (!$validation->checkVatNumber($country_iso_code_2, $vat_number)) {
                            $this->vatNumberStatus = 0; //- Not validated
                        } else {
                            $this->vatNumberStatus = 1; //- Validated
                        }
                    }
                }
            }
        }
        return $vat_ok;    
    }
    
    protected function checkVatIsRefundable($customers_id = false, $address_id = false)
    {
        if (!$this->vatGathered) {
            $this->vatValidated = false;
            $this->vatIsRefundable = false;
            $this->vatNumber = '';
            if (isset($_SESSION['customer_id'])) {
                if ($customers_id === false) {
                    $customers_id = $_SESSION['customer_id'];
                }
                if ($address_id === false) {
                    $address_id = (isset($_SESSION['billto'])) ? $_SESSION['billto'] : $_SESSION['customer_default_address_id'];
                }
                $check = $GLOBALS['db']->Execute(
                    "SELECT entry_country_id, entry_vat_number, entry_vat_validated
                       FROM " . TABLE_ADDRESS_BOOK . "
                      WHERE address_book_id = " . (int)$address_id . "
                        AND customers_id = " . (int)$customers_id . "
                      LIMIT 1"
                );
                if (!$check->EOF) {
                    if ($this->isVatCountry($check->fields['entry_country_id'])) {
                        $this->vatNumber = $check->fields['entry_vat_number'];
                        $this->vatValidated = $check->fields['entry_vat_validated'];
                        if ($this->vatValidated == 1 && (VAT4EU_IN_COUNTRY_REFUND == 'true' || STORE_COUNTRY != $check->fields['entry_country_id'])) {
                            $this->vatIsRefundable = true;
                        }
                    }
                }
            }
        }
        return $this->vatIsRefundable;
    }
    
    protected function formatAddress($address_elements, $current_address)
    {
        // -----
        // Determine whether the address being formatted "qualifies" for the insertion of the VAT Number.
        //
        $address_out = false;
        if ($this->vatNumber != '') {
            // -----
            // Determine whether the VAT Number should be appended to the specified address, based
            // on the page from which the zen_address_format request was made.
            //
            switch ($GLOBALS['current_page_base']) {
                // -----
                // These pages include a single address reference, so the VAT Number is always displayed.  For these
                // pages, the current customer/checkout session values provide the values associated with the vatNumber
                // and its validation status.
                //
                case FILENAME_ADDRESS_BOOK:
                case FILENAME_ADDRESS_BOOK_PROCESS:
                case FILENAME_CHECKOUT_PAYMENT:
                case FILENAME_CHECKOUT_PAYMENT_ADDRESS:
                case FILENAME_CREATE_ACCOUNT_SUCCESS:
                    $show_vat_number = true;
                    break;
                    
                // -----
                // These pages include multiple address-blocks' display; the second call to zen_address_format references
                // the billing address, so the VAT Number is displayed.
                //
                // NOTE: This observer's previous "hook" into the order-class' query function has populated the order-object's
                // VAT-related fields.
                //
                case FILENAME_ACCOUNT_HISTORY_INFO:
                case FILENAME_CHECKOUT_SUCCESS:
                    $this->addressFormatCount++;
                    if ($this->addressFormatCount == 2) {
                        $show_vat_number = true;
                        $this->vatNumber = $GLOBALS['order']->billing['billing_vat_number'];
                        $this->vatValidated = $GLOBALS['order']->billing['billing_vat_validated'];
                    }
                    break;
                    
                // -----
                // These pages include multiple address-blocks' display; the first call to zen_address_format references
                // the billing address, so the VAT Number is displayed.
                //
                case FILENAME_CHECKOUT_CONFIRMATION:
                    $this->addressFormatCount++;
                    if ($this->addressFormatCount == 1) {
                        $show_vat_number = true;
                    }
                    break;
                    
                // -----
                // Some of the other pages have multiple address-blocks with conditional displays, so weed them
                // out now.
                //
                // Any other page, the VAT Number is not displayed.  A notification is made, however, to enable
                // other pages that display order-related addresses to "tack on".
                //
                default:
                    // -----
                    // During the checkout-process page, the orders' class is generating billing and shipping
                    // address blocks (for both HTML and TEXT emails).  If the order is "virtual", only the billing 
                    // addresses are generated; otherwise, the billing addresses are associated with the third
                    // and fourth function calls.
                    //
                    $current_page_base = $GLOBALS['current_page_base'];
                    if ($current_page_base == FILENAME_CHECKOUT_PROCESS) {
                        $this->addressFormatCount++;
                        if ($GLOBALS['order']->content_type != 'virtual') {
                            if ($this->addressFormatCount == 3 || $this->addressFormatCount == 4) {
                                $show_vat_number = true;
                            }
                        } else {
                            $show_vat_number = true;
                        }
                    // -----
                    // If the "One-Page Checkout" plugin is installed, the billing address-block is the first
                    // one created.
                    //
                    } elseif (defined('FILENAME_CHECKOUT_ONE') && $current_page_base == FILENAME_CHECKOUT_ONE) {
                        $this->addressFormatCount++;
                        if ($this->addressFormatCount == 1) {
                            $show_vat_number = true;
                        }
                    // -----
                    // Other pages, fire a notification to allow additional display of the VAT Number within
                    // a formatted address.
                    //
                    } else {
                        $show_vat_number = false;
                        $this->notify('NOTIFY_VAT4EU_ADDRESS_DEFAULT', $address_elements, $current_address, $show_vat_number);
                    }
                    break;
            }
            // -----
            // If the VAT Number is to be displayed as part of the address-block, append its value to the
            // end of the address, including the "unverified" tag if the number has not been validated.
            //
            if ($show_vat_number) {
                $address_out = $current_address . $address_elements['cr'] . VAT4EU_ENTRY_VAT_NUMBER . ' ' . $this->vatNumber;
                if ($this->vatValidated != 1) {
                    $address_out .= VAT4EU_UNVERIFIED;
                }
            }
        }
        return $address_out;
    }
    
    // -----
    // This function returns a boolean indicator, identifying whether (true) or not (false) the
    // country associated with the "countries_id" input qualifies for this plugin's processing.
    //
    protected function isVatCountry($countries_id)
    {
        return in_array($this->getCountryIsoCode2($countries_id), $this->vatCountries);
    }
    
    private function debug($message)
    {
        $this->debug[] = $message;
    }

}