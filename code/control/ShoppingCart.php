<?php

/**
 * Holder for items in the shopping cart and interacting with them, as
 * well as rendering these items into an interface that allows editing
 * of items,
 *
 * @author i-lateral (http://www.i-lateral.com)
 * @package checkout
 */
class ShoppingCart extends Controller {

    /**
     * URL Used to access this controller
     *
     * @var string
     * @config
     */
    private static $url_segment = 'checkout/cart';

    /**
     * Name of the current controller. Mostly used in templates.
     *
     * @var string
     * @config
     */
    private static $class_name = "ShoppingCart";

    private static $allowed_actions = array(
        "remove",
        "emptycart",
        "clear",
        "update",
        "usediscount",
        "CartForm",
        "PostageForm",
        "DiscountForm"
    );

    /**
     * Overwrite the default title for this controller which is taken
     * from the translation files. This is used for Title and MetaTitle
     * variables in templates.
     *
     * @var string
     * @config
     */
    private static $title;

    /**
     * Track all items stored in the current shopping cart
     *
     * @var ArrayList
     */
    protected $items;

    /**
     * Track a discount object placed against this cart
     *
     * @var ArrayList
     */
    protected $discount;

    /**
     * Show the discount form on the shopping cart
     *
     * @var boolean
     * @config
     */
    private static $show_discount_form = false;


    /**
     * Getters and setters
     *
     */
    public function getClassName() {
        return self::config()->class_name;
    }

    public function getTitle() {
        return ($this->config()->title) ? $this->config()->title : _t("Checkout.CartName", "Shopping Cart");
    }

    public function getMetaTitle() {
        return $this->getTitle();
    }

    public function getShowDiscountForm() {
        return $this->config()->show_discount_form;
    }

    public function getItems() {
        return $this->items;
    }

    public function getDiscount() {
        return $this->discount;
    }

    public function setDiscount(Discount $discount) {
        $this->discount = $discount;
    }
        
    /**
     * Get the link to this controller
     * 
     * @return string
     */
    public function Link($action = null) {
        return Controller::join_links(
            Director::BaseURL(),
            $this->config()->url_segment,
            $action
        );
    }

    /**
     * Set postage that is available to the shopping cart based on the
     * country and zip code submitted
     *
     * @param $country 2 character country code
     * @param $code Zip or Postal code
     * @return ShoppingCart
     */
    public function setAvailablePostage($country, $code) {
        // Set postage data and save into a session
        $postage_areas = Checkout::getPostageAreas($country, $code);
        Session::set("Checkout.AvailablePostage", $postage_areas);

        return $this;
    }

    /**
     * Shortcut for ShoppingCart::create, exists because create()
     * doesn't seem quite right.
     *
     * @return ShoppingCart
     */
    public static function get() {
        return ShoppingCart::create();
    }


    public function __construct() {
        // If items are stored in a session, get them now
        if(Session::get('Checkout.ShoppingCart.Items'))
            $this->items = unserialize(Session::get('Checkout.ShoppingCart.Items'));
        else
            $this->items = ArrayList::create();

        // If discounts stored in a session, get them, else create new list
        if(Session::get('Checkout.Discount'))
            $this->discount = unserialize(Session::get('Checkout.Discount'));

        // If we don't have any discounts, a user is logged in and he has
        // access to discounts through a group, add the discount here
        if(!$this->discount && Member::currentUserID()) {
            $member = Member::currentUser();
            $this->discount = $member->getDiscount();
            Session::set('Checkout.Discount', serialize($this->discount));
        }

        parent::__construct();
    }
    
    /**
     * Return a rendered button for the shopping cart
     *
     * @return string
     */
    public function getViewCartButton(){
        return $this
            ->owner
            ->renderWith('ViewCartButton');
    }

    /**
     * Actions for this controller
     */

    /**
     * Default acton for the shopping cart
     */
    public function index() {
        $this->extend("onBeforeIndex");

        return $this->renderWith(array(
            'ShoppingCart',
            'Checkout',
            'Page'
        ));
    }

    /**
     * Remove a product from ShoppingCart Via its ID. This action
     * expects an ID to be sent through the URL that matches a specific
     * key added to an item in the cart
     *
     * @return Redirect
     */
    public function remove() {
        $key = $this->request->param('ID');

        if(!empty($key)) {
            foreach($this->items as $item) {
                if($item->Key == $key)
                    $this->items->remove($item);
            }

            $this->save();
        }

        return $this->redirectBack();
    }

    /**
     * Action that will clear shopping cart and associated sessions
     *
     */
    public function emptycart() {
        $this->extend("onBeforeEmpty");
        $this->removeAll();
        $this->save();

        return $this->redirectBack();
    }


    /**
     * Action used to add a discount to the users session via a URL.
     * This is preferable to using the dicount form as disount code
     * forms seem to provide a less than perfect user experience
     *
     */
    public function usediscount() {
        $this->extend("onBeforeUseDiscount");

        $code_to_search = $this->request->param("ID");
        $code = false;

        if(!$code_to_search)
            return $this->httpError(404, "Page not found");

        // First check if the discount is already added (so we don't
        // query the DB if we don't have to).
        if(!$this->discount || ($this->discount && $this->discount->Code != $code_to_search)) {
            $codes = Discount::get()
                ->filter("Code", $code_to_search)
                ->exclude("Expires:LessThan", date("Y-m-d"));

            if($codes->exists()) {
                $this->discount = $codes->first();
                $this->save();
            }
        } elseif($this->discount && $this->discount->Code == $code_to_search)
            $code = $this->discount;

        return $this
            ->customise(array(
                "Discount" => $code
            ))->renderWith(array(
                'ShoppingCart_discount',
                'Checkout',
                'Page'
            ));
    }

    /**
     * Add a product to the shopping cart via its ID number.
     *
     * @param classname ClassName of the object you wish to add
     * @param id ID of the object you wish to add
     * @param quantity number of this item to add
     * @param customise array of custom options for this product, needs to be a
     *        multi dimensional array with each item of format:
     *          -  "Title" => (str)"Item title"
     *          -  "Value" => (str)"Item Value"
     *          -  "ModifyPrice" => (float)"Modification to price"
     */
    public function add($classname, $id, $quantity = 1, $customise = array()) {
        $added = false;
        
        // Get our object to add or return error
        if(!($object = $classname::get()->byID($id)))
            return $this->httpError(404);

        // Make a string to match id's against ones already in the cart
        $key = ($customise) ? (int)$object->ID . ':' . base64_encode(serialize($customise)) : (int)$object->ID;

        // Check if object already in the cart and update quantity
        foreach($this->items as $item) {
            if($item->Key == $key) {
                $this->update($item->Key, ($item->Quantity + $quantity));
                $added = true;
            }
        }

        // If no update was sucessfull then add to cart items
        if(!$added) {
            $custom_data = new ArrayList();

            // Convert custom data into object
            foreach($customise as $custom_item) {
                $custom_data->add(new ArrayData(array(
                    'Title' => ucwords(str_replace(array('-','_'), ' ', $custom_item["Title"])),
                    'Value' => $custom_item["Value"],
                    'ModifyPrice' => $custom_item['ModifyPrice']
                )));
            }

            $cart_item = ArrayData::create(array(
                "Key"           => $key,
                "Object"        => $object,
                "Customised"    => $custom_data,
                "Quantity"      => $quantity
            ));

            $this->extend("onBeforeAdd", $cart_item);

            $this->items->add($cart_item);
            $this->save();

            $this->extend("onAfterAdd");
        }
    }

    /**
     * Find an existing item and update its quantity
     *
     * @param Item
     * @param Quantity
     */
    public function update($item_key, $quantity) {
        foreach($this->items as $item) {
            if ($item->Key === $item_key) {
                $this->extend("onBeforeUpdate", $item);

                $item->Quantity = $quantity;
                $this->save();

                $this->extend("onAfterUpdate", $item);
                return true;
            }
        }

        return false;
     }

    /**
     * Empty the shopping cart object of all items.
     *
     */
    public function removeAll() {
        foreach($this->items as $item) {
            $this->items->remove($item);
        }
    }

    /**
     * Save the current products list and postage to a session.
     *
     */
    public function save() {
        Session::clear("Checkout.PostageID");

        // Save cart items
        Session::set(
            "Checkout.ShoppingCart.Items",
            serialize($this->items)
        );

        // Save cart discounts
        Session::set(
            "Checkout.ShoppingCart.Discount",
            serialize($this->discount)
        );

        // Update available postage
        if($data = Session::get("Form.Form_PostageForm.data")) {
            $country = $data["Country"];
            $code = $data["ZipCode"];
            $this->setAvailablePostage($country, $code);
        }
    }

    /**
     * Clear the shopping cart object and destroy the session. Different to
     * empty, as that retains the session.
     *
     */
    public function clear() {
        Session::clear('Checkout.ShoppingCart.Items');
        Session::clear('Checkout.ShoppingCart.Discount');
        Session::clear("Checkout.PostageID");
    }

    /**
     * Find the total weight of all items in the shopping cart
     *
     * @return Decimal
     */
    public function TotalWeight() {
        $total = 0;
        $return = new Decimal();

        foreach($this->items as $item) {
            if($item->Object->Weight)
                $total = $total + ($item->Object->Weight * $item->Quantity);
        }
        
        $this->extend("updateTotalWeight", $total);
        
        $return->setValue($total);
        return $return;
    }

    /**
     * Find the total quantity of items in the shopping cart
     *
     * @return Int
     */
    public function TotalItems() {
        $total = 0;
        $return = new Int();

        foreach($this->items as $item) {
            $total = $total + $item->Quantity;
        }
        
        $this->extend("updateTotalItems", $total);

        $return->setValue($total);
        return $return;
    }

    /**
     * Find the cost of all items in the cart, without any tax.
     *
     * @return Currency
     */
    public function SubTotalCost() {
        $total = 0;
        $return = new Currency();

        foreach($this->items as $item) {
            if($item->Object->Price)
                $total = $total + ($item->Quantity * $item->Object->Price);
        }
        
        $this->extend("updateSubTotalCost", $total);

        $return->setValue($total);
        return $return;
    }

    /**
     * Get the cost of postage
     *
     * @return Currency
     */
    public function PostageCost() {
        $total = 0;
        $return = new Currency();
        
        if($postage = PostageArea::get()->byID(Session::get("Checkout.PostageID")))
            $total = $postage->Cost;
            
        $this->extend("updatePostageCost", $total);

        $return->setValue($total);
        return $return;
    }

    /**
     * Find the total discount based on discount items added.
     *
     * @return Currency
     */
    public function DiscountAmount() {
        $total = 0;
        $return = new Currency();
        $discount = $this->discount;

        if($discount) {
            $subtotal = $this->SubTotalCost()->RAW();
            
            // If fixed and subtotal is greater than discount, add full
            // discount, else ensure we don't get a negative total!
            if($subtotal && $discount->Type == "Fixed") {
                if($subtotal > $discount->Amount)
                    $total = $discount->Amount;
                else
                    $total = $subtotal;
            }
            // If percentage and subtotal, calculate discount
            elseif($subtotal && $discount->Type == "Percentage" && $discount->Amount)
                $total = (($discount->Amount / 100) * $subtotal);
        }
        
        $this->extend("updateDiscountAmount", $total);

        $return->setValue($total);
        return $return;
    }

    /**
     * Find the total cost of tax for the items in the cart, as well as shipping
     * (if set)
     *
     * @return Currency
     */
    public function TaxCost() {
        $total = 0;
        $config = SiteConfig::current_site_config();
        $return = new Currency();
        
        if($config->TaxRate > 0) {
            $total = ($this->SubTotalCost()->RAW() + $this->PostageCost()->RAW()) - $this->DiscountAmount()->RAW();
            $total = ($total > 0) ? ((float)$total / 100) * $config->TaxRate : 0;
        }

        $this->extend("updateTaxCost", $total);

        $return->setValue($total);
        return $return;
    }

    /**
     * Find the total cost of for all items in the cart, including tax and
     * shipping (if applicable)
     *
     * @return Currency
     */
    public function TotalCost() {
        $subtotal = $this->SubTotalCost()->RAW();
        $discount = $this->DiscountAmount()->RAW();
        $postage = $this->PostageCost()->RAW();
        $tax = $this->TaxCost()->RAW();
        $return = new Currency();

        $total = ($subtotal - $discount) + $postage + $tax;

        $this->extend("updateTotalCost", $total);

        $return->setValue($total);
        return $return;
    }


    /**
     * Form responsible for listing items in the shopping cart and
     * allowing management (such as addition, removal, etc)
     *
     * @return Form
     */
    public function CartForm() {
        $fields = new FieldList();

        $actions = new FieldList(
            FormAction::create('doUpdate', _t('Checkout.UpdateCart','Update Cart'))
                ->addExtraClass('btn')
                ->addExtraClass('btn-blue')
        );

        $form = Form::create($this, "CartForm", $fields, $actions)
            ->addExtraClass("forms")
            ->setTemplate("ShoppingCartForm");

        $this->extend("updateCartForm", $form);

        return $form;
    }

    /**
     * Form that allows you to add a discount code which then gets added
     * to the cart's list of discounts.
     *
     * @return Form
     */
    public function DiscountForm() {
        $fields = new FieldList(
            TextField::create(
                "DiscountCode",
                _t("Checkout.DiscountCode", "Discount Code")
            )->setAttribute(
                "placeholder",
                _t("Checkout.EnterDiscountCode", "Enter a discount code")
            )
        );

        $actions = new FieldList(
            FormAction::create('doAddDiscount', _t('Checkout.Add','Add'))
                ->addExtraClass('btn')
                ->addExtraClass('btn-blue')
        );

        $form = Form::create($this, "DiscountForm", $fields, $actions)
            ->addExtraClass("forms");

        $this->extend("updateDiscountForm", $form);

        return $form;
    }

    /**
     * Form responsible for estimating shipping based on location and
     * postal code
     *
     * @return Form
     */
    public function PostageForm() {
        if(!Checkout::config()->simple_checkout) {
            $available_postage = Session::get("Checkout.AvailablePostage");

            // Setup default postage fields
            $country_select = CompositeField::create(
                CountryDropdownField::create('Country',_t('Checkout.Country','Country')),
                TextField::create("ZipCode",_t('Checkout.ZipCode',"Zip/Postal Code"))
            )->addExtraClass("size1of2")
            ->addExtraClass("unit")
            ->addExtraClass("unit-50");

            // If we have stipulated a search, then see if we have any results
            // otherwise load empty fieldsets
            if($available_postage) {
                $search_text = _t('Checkout.Update',"Update");

                // Loop through all postage areas and generate a new list
                $postage_array = array();
                foreach($available_postage as $area) {
                    $area_currency = new Currency("Cost");
                    $area_currency->setValue($area->Cost);
                    $postage_array[$area->ID] = $area->Title . " (" . $area_currency->Nice() . ")";
                }

                $postage_select = CompositeField::create(
                    OptionsetField::create(
                        "PostageID",
                        _t('Checkout.SelectPostage',"Select Postage"),
                        $postage_array
                    )
                )->addExtraClass("size1of2")
                ->addExtraClass("unit")
                ->addExtraClass("unit-50");

                $confirm_action = CompositeField::create(
                    FormAction::create("doSavePostage", _t('Checkout.Confirm',"Confirm"))
                        ->addExtraClass('btn')
                        ->addExtraClass('btn-green')
                )->addExtraClass("size1of2")
                ->addExtraClass("unit")
                ->addExtraClass("unit-50");
            } else {
                $search_text = _t('Checkout.Search',"Search");
                $postage_select = CompositeField::create()
                    ->addExtraClass("size1of2")
                    ->addExtraClass("unit")
                    ->addExtraClass("unit-50");
                $confirm_action = CompositeField::create()
                    ->addExtraClass("size1of2")
                    ->addExtraClass("unit")
                    ->addExtraClass("unit-50");
            }

            // Set search field
            $search_action = CompositeField::create(
                FormAction::create("doGetPostage", $search_text)
                    ->addExtraClass('btn')
            )->addExtraClass("size1of2")
            ->addExtraClass("unit")
            ->addExtraClass("unit-50");


            // Setup fields and actions
            $fields = new FieldList(
                CompositeField::create($country_select,$postage_select)
                    ->addExtraClass("line")
                    ->addExtraClass("units-row")
            );

            $actions = new FieldList(
                CompositeField::create($search_action,$confirm_action)
                    ->addExtraClass("line")
                    ->addExtraClass("units-row")
            );

            $required = RequiredFields::create(array(
                "Country",
                "ZipCode"
            ));

            $form = Form::create($this, 'PostageForm', $fields, $actions, $required)
                ->addExtraClass('forms')
                ->addExtraClass('forms-inline');

            // Check if the form has been re-posted and load data
            $data = Session::get("Form.{$form->FormName()}.data");
            if(is_array($data)) $form->loadDataFrom($data);

            // Check if the postage area has been set, if so, Set Postage ID
            $data = array();
            $data["PostageID"] = Session::get("Checkout.PostageID");
            if(is_array($data)) $form->loadDataFrom($data);

            // Extension call
            $this->extend("updatePostageForm", $form);

            return $form;
        }
    }

    /**
     * Action that will update cart
     *
     * @param type $data
     * @param type $form
     */
    public function doUpdate($data, $form) {
        foreach($this->items as $cart_item) {
            foreach($data as $key => $value) {
                $sliced_key = explode("_", $key);
                if($sliced_key[0] == "Quantity") {
                    if(isset($cart_item) && ($cart_item->Key == $sliced_key[1])) {
                        if($value > 0) {
                            $this->update($cart_item->Key, $value);
                        } else
                            $this->remove($cart_item->Key);
                    }
                }
            }
        }

        $this->save();

        return $this->redirectBack();
    }

    /**
     * Action that will find a discount based on the code
     *
     * @param type $data
     * @param type $form
     */
    public function doAddDiscount($data, $form) {
        $code_to_search = $data['DiscountCode'];

        // First check if the discount is already added (so we don't
        // query the DB if we don't have to).
        if(!$this->discount || ($this->discount && $this->discount->Code != $code_to_search)) {
            $code = Discount::get()
                ->filter("Code", $code_to_search)
                ->exclude("Expires:LessThan", date("Y-m-d"))
                ->first();

            if($code) $this->discount = $code;
        }

        $this->save();

        return $this->redirectBack();
    }

    /**
     * Search and find applicable postage rates based on submitted data
     *
     * @param $data
     * @param $form
     */
    public function doGetPostage($data, $form) {
        $country = $data["Country"];
        $code = $data["ZipCode"];

        $this->setAvailablePostage($country, $code);

        // Set the form pre-populate data before redirecting
        Session::set("Form.{$form->FormName()}.data", $data);

        $url = Controller::join_links($this->Link(),"#{$form->FormName()}");

        return $this->redirect($url);
    }

    /**
     * Save applicable postage data to session
     *
     * @param $data
     * @param $form
     */
    public function doSavePostage($data, $form) {
        Session::set("Checkout.PostageID", $data["PostageID"]);

        $url = Controller::join_links($this->Link(),"#{$form->FormName()}");

        return $this->redirect($url);
    }
}