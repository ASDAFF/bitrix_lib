<?php
/**
 * Created by PhpStorm.
 * User: Администратор
 * Date: 16.06.14
 * Time: 11:54
 */

namespace Cpeople\Classes\Catalog;

class Cart
{
    protected $itemClass = '\Cpeople\Classes\Catalog\CartItem';
    protected $itemsRaw;
    protected $items;
    protected $tainted = true;
    protected $location;
    protected $deliveryId;
    protected $deliveryPrice;
    protected $paymentId;

    public function __construct()
    {
        \CModule::IncludeModule('sale');

        if (!empty($_SESSION['CART_LOCATION_ID']))
        {
            $this->locationId = $_SESSION['CART_LOCATION_ID'];
        }

        if (!empty($_SESSION['CART_DELIVERY_ID']))
        {
            $this->deliveryId = $_SESSION['CART_DELIVERY_ID'];
        }

        if (!empty($_SESSION['CART_DELIVERY_PRICE']))
        {
            $this->deliveryPrice = $_SESSION['CART_DELIVERY_PRICE'];
        }

        if (!empty($_SESSION['CART_PAYMENT_ID']))
        {
            $this->paymentId = $_SESSION['CART_PAYMENT_ID'];
        }
    }

    public function clearCache()
    {
        unset($this->items);
    }

    public function getCount()
    {
        return count($this->getItems());
    }

    public function getItemsRaw()
    {
        $this->getItems();
        return $this->itemsRaw;
    }

    public function setLocationId($locationId)
    {
        $this->locationId = $locationId;
        $_SESSION['CART_LOCATION_ID'] = $this->locationId;
    }

    public function getLocationId()
    {
        return $this->locationId;
    }

    public function getLocation()
    {
        if (!$this->getLocationId())
        {
            throw new \Exception('Не указано место доставки ' . __METHOD__);
        }

        $retval = false;

        $res = \CSaleLocation::GetList(array(), array(
            'LID' => LANGUAGE_ID,
            'ID' => $this->getLocationId()
        ));

        while ($city = $res->GetNext())
        {
            if(!\Bitrix\Sale\SalesZone::checkCityId($city['CITY_ID'], SITE_ID)) continue;
            if (empty($city['CITY_NAME'])) continue;
            $retval = $city;
        }

        return $retval;
    }

    public function setDeliveryId($id)
    {
        $this->deliveryId = (int) $id;
        $_SESSION['CART_DELIVERY_ID'] = $this->deliveryId;
    }

    public function getDeliveryId()
    {
        return $this->deliveryId;
    }

    public function setDeliveryPrice($price)
    {
        $this->deliveryPrice = (float) $price;
        $_SESSION['CART_DELIVERY_PRICE'] = $this->deliveryPrice;
    }

    public function setPaymentId($id)
    {
        $this->paymentId = (int) $id;
        $_SESSION['CART_PAYMENT_ID'] = $this->paymentId;
    }

    public function getPaymentId()
    {
        return $this->paymentId;
    }

    public function getDeliveryPrice($refresh = false)
    {
        if ($refresh || !$this->deliveryPrice)
        {
            $this->deliveryPrice = $this->getCurrentDelivery()['PRICE'];
        }

        return $this->deliveryPrice;
    }

    public function getItems()
    {
        if (!isset($this->items) || $this->tainted)
        {
            $className = $this->itemClass;

            $result = array();

            \CModule::IncludeModule('sale');

            $dbBasketItems = \CSaleBasket::GetList(
                array("NAME" => "ASC", "ID" => "ASC"),
                array("FUSER_ID" => \CSaleBasket::GetBasketUserID(), /*"LID" => SITE_ID,*/ "ORDER_ID" => "NULL"),
                false,
                false,
                array()
            );

            while ($item = $dbBasketItems->Fetch())
            {
                $this->itemsRaw[] = $item;
                $item = new $className($item, $this);
                $this->items[] = $item;
            }

            $this->tainted = false;
        }

        return $this->items;
    }

    public function getTotal()
    {
        $retval = 0;

        foreach ((array) $this->getItems() as $item)
        {
            $retval += $item->getSum();
        }

        return $retval;
    }

    public function getTotalDelivery()
    {
        $deliveryPrice = 0;

        // не прерываемся, если служба доставки не определена
        try
        {
            $deliveryPrice = $this->getDeliveryPrice();
        }
        catch (\Exception $e)
        {

        }

        return $this->getTotal() + $deliveryPrice;
    }


    public function setTainted($tainted)
    {
        $this->tainted = (bool) $tainted;
    }

    public function removeById($id)
    {
        $items = $this->getItems();

        foreach ($items as $item)
        {
            if ($item->id == $id)
            {
                $item->remove();
                break;
            }
        }
    }

    public function getWeight()
    {
        $weight = 0;

        foreach ($this->getItems() as $item)
        {
            $weight += $item->getWeight();
        }

        return $weight;
    }

    public function getDeliveryOptions($locationId = null, $from = null)
    {
        if (empty($locationId))
        {
            $locationId = $this->getLocationId();
        }

        if (empty($locationId))
        {
            throw new \Exception('Для получения служб доставки нужно указать место доставки ' . __METHOD__);
        }

        $filter = array(
            'COMPABILITY' => array(
                'WEIGHT' => $this->getWeight(),
                'PRICE' => $this->getTotal(),
                'LOCATION_FROM' => !empty($from) ? $from : \COption::GetOptionString('sale', 'location', false, SITE_ID),
                'LOCATION_TO' => $locationId,
                'ITEMS' => $this->getItemsRaw()
            )
        );

        $delivery = array();

        $res = \CSaleDeliveryHandler::GetList(array('SORT' => 'ASC'), $filter);

        while ($deliveryItem = $res->Fetch())
        {
            if (!is_array($deliveryItem) || !is_array($deliveryItem['PROFILES'])) continue;

            foreach ($deliveryItem['PROFILES'] as $key => $profile)
            {
                $deliveryProfile = array(
                    'ID' => $deliveryItem['SID'] . ':' . $key,
                    'SID' => $deliveryItem['SID'],
                    'PROFILE' => $key,
                    'NAME' => $deliveryItem['NAME'],
                    'TITLE' => $profile['TITLE'],
                    'DESCRIPTION' => $deliveryItem['DESCRIPTION'],
                    'DESCRIPTION_INNER' => $deliveryItem['DESCRIPTION_INNER'],
                    'BASE_CURRENCY' => $deliveryItem['BASE_CURRENCY'],
                    'HANDLER' => $deliveryItem['HANDLER'],
                    'DELIVERY' => $deliveryItem
                );

                $delivery[] = $deliveryProfile;
            }
        }

        $res = \CSaleDelivery::GetList(
            array('SORT'=>'ASC', 'NAME'=>'ASC'),
            array(
                'LID' => SITE_ID,
                '+<=WEIGHT_FROM' => $this->getWeight(),
                '+>=WEIGHT_TO' => $this->getWeight(),
                'ACTIVE' => 'Y',
                'LOCATION' => $locationId,
            )
        );

        while ($deliveryItem = $res->Fetch())
        {
            $deliveryDescription = \CSaleDelivery::GetByID($deliveryItem['ID']);
            $deliveryItem['DESCRIPTION'] = htmlspecialcharsbx($deliveryDescription['DESCRIPTION']);
            $delivery[] = $deliveryItem;
        }

        foreach ($delivery as $k => $deliveryItem)
        {
            if ($deliveryItem['NAME'] == 'Самовывоз') continue;

            if (empty($deliveryItem['SID'])) continue;

            /**
             * TODO
             * dimensions
             */
            $arOrderTmpDel = array(
                'PRICE' => $this->getTotal(),
                'WEIGHT' => $this->getWeight() / 1000,
                'DIMENSIONS' => array(10,10,10),
                'LOCATION_FROM' => COption::GetOptionInt('sale', 'location'),
                'LOCATION_TO' => $locationId,
                'ITEMS' => $this->getItemsRaw(),
            );

            $price = \CSaleDeliveryHandler::CalculateFull(
                $deliveryItem['SID'],
                $deliveryItem['PROFILE'],
                $arOrderTmpDel,
                'RUB'
            );

            $delivery[$k]['PRICE'] = $price['VALUE'];
        }

        return $delivery;
    }

    public function getCurrentDelivery()
    {
        if (!$this->getDeliveryId())
        {
            throw new \Exception('Служба доставки не установлена ' . __METHOD__);
        }

        $retval = false;

        $delivery = $this->getDeliveryOptions();

        foreach ($delivery as $deliveryItem)
        {
            if ($deliveryItem['ID'] == $this->getDeliveryId())
            {
                $retval = $deliveryItem;
                break;
            }
        }

        return $retval;
    }

    public function getPaysystems($deliveryId = null)
    {
        if (empty($deliveryId))
        {
            $deliveryId = $this->deliveryId;
        }

        if (empty($deliveryId))
        {
            throw new \Exception('Для получения способов оплаты нужно указать службу доставки');
        }

        $paySystems = array();

        $paySystemFilter = array(
            'ACTIVE' => 'Y',
            'PERSON_TYPE_ID' => 1,
            'PSA_HAVE_PAYMENT' => 'Y',
        );

        $res = \CSalePaySystem::GetList(
            array("SORT" => "ASC", "PSA_NAME" => "ASC"),
            $paySystemFilter
        );

        while ($paySystem = $res->Fetch())
        {
            if (!\CSaleDelivery2PaySystem::isPaySystemApplicable($paySystem['ID'], $deliveryId))
            {
                continue;
            }

            $check = \CSalePaySystemsHelper::checkPSCompability(
                $paySystem['PSA_ACTION_FILE'],
                $this->getItemsRaw(),
                $this->getTotal(),
                $this->deliveryPrice,
                $this->location
            );

            if (!$check)
            {
                continue;
            }

            $paySystem['PRICE'] = \CSalePaySystemsHelper::getPSPrice(
                $paySystem,
                $this->getTotal(),
                $this->deliveryPrice,
                $this->location
            );

            $paySystems[] = $paySystem;
        }

        return $paySystems;
    }

    public function getCurrentPayment()
    {
        if (!$this->getPaymentId())
        {
            throw new \Exception('Способ оплаты не установлен ' . __METHOD__);
        }

        $retval = false;

        $paymentSystems = $this->getPaysystems();

        foreach ($paymentSystems as $system)
        {
            if ($system['ID'] == $this->getPaymentId())
            {
                $retval = $system;
                break;
            }
        }

        return $retval;
    }
}