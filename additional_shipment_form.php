<?php
\Bitrix\Main\EventManager::getInstance()->addEventHandler('main', 'OnEpilog', 'createFastShipmentBtn');

use Bitrix\Sale;


function createFastShipmentBtn()
{
    global $APPLICATION;
    if ($APPLICATION->GetCurPage() === '/bitrix/admin/sale_order_view.php' && bitrix_sessid()) {
        include($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
        include $_SERVER["DOCUMENT_ROOT"] . '/bitrix/php_interface/include/additional_shipment_form/styles.php';
        include $_SERVER["DOCUMENT_ROOT"] . '/bitrix/php_interface/include/additional_shipment_form/functions.php';

        $order = \Bitrix\Sale\Order::load($_REQUEST['ID']);
        $shipmentCollection = $order->getShipmentCollection();
        $shipments = array();
        $notShippedProducts = array();
        $deliveryList = getDeliveryServices();
        $basketItems = $order->getBasket()->getBasketItems();
        $products = array();

        foreach ($basketItems as $basketItem) {
            $products[$basketItem->getProductId()] = array(
                'NAME' => $basketItem->getField('NAME'),
                'QUANTITY' => $basketItem->getQuantity(),
            );
        }


        foreach ($shipmentCollection as $shipment) {
            if ($shipment->isSystem()) continue;
            $shipmentId = $shipment->getId();
            $shipmentNumber = $shipment->getField('ACCOUNT_NUMBER');
            $isDeducted = $shipment->getField('DEDUCTED');
            $allowDelivery = $shipment->getField('ALLOW_DELIVERY');
            $shipmentIsReturned = $shipment->getField('STATUS_ID') == 'RS';
            $shipments[$shipmentId] = array(
                "ID" => $shipmentId,
                "ACCOUNT_NUMBER" => $shipmentNumber,
                "DEDUCTED" => $isDeducted,
                "RETURNED" => $shipmentIsReturned
            );

            $shipmentItemCollection = $shipment->getShipmentItemCollection();

            foreach ($shipmentItemCollection as $item) {
                $productId = $item->getProductId();
                $productQuantity = $item->getQuantity();
                $basketItem = $item->getBasketItem();
                $productName = $basketItem->getField('NAME');

                if (!$shipmentIsReturned && $isDeducted === 'N' && $allowDelivery === 'N') {
                    $notShippedProducts[$productId]['NAME'] = $productName;
                    $notShippedProducts[$productId]['QUANTITY'] = $productQuantity;
                }

                if ($shipmentIsReturned) {
                    $products[$productId]['QUANTITY'] -= $productQuantity;

                    if ($products[$productId]['QUANTITY'] == 0)
                        unset($products[$productId]);
                }


            }
        }
        ?>
        <script>
            const shipments = <?=CUtil::PhpToJSObject($shipments);?>;
            const products = <?=CUtil::PhpToJSObject($products);?>;
            const notShippedProducts = <?=CUtil::PhpToJSObject($notShippedProducts);?>;
            const deliveryList = <?=CUtil::PhpToJSObject($deliveryList);?>;
            const currentDir = <?=CUtil::PhpToJSObject(__DIR__);?>;
            const orderId = <?=CUtil::PhpToJSObject($_REQUEST['ID']);?>;


            document.addEventListener('DOMContentLoaded', function () {
                const $shipmentsContainer = document.getElementById('sale-adm-order-shipments-content');
                wait();

                function wait() {
                    setTimeout(() => {
                        $shipmentsContainer.childElementCount <= 1 ? wait() : insertHtml();
                    }, 100);
                }

                function insertHtml() {
                    const $shipmentBtn = createElement('div', 'open-popup-shipment');
                    $shipmentBtn.innerHTML = '<input type="button" data-type="shipments" class="adm-order-block-add-button" value="Быстрое создание отгрузки">';
                    $shipmentsContainer.append($shipmentBtn);
                    $shipmentBtn.addEventListener('click', openShipmentPopup);

                    const $returnProductsBtn = createElement('div', 'open-popup-shipment');
                    $returnProductsBtn.innerHTML = '<input type="button" data-type="returnProducts" class="adm-order-block-add-button" value="Возврат товаров" style="display: none;">';
                    $shipmentsContainer.append($returnProductsBtn);
                    $returnProductsBtn.addEventListener('click', openShipmentPopup);

                    for (const [id, shipment] of Object.entries(shipments)) {
                        let $shipment = document.getElementById(`shipment_${id}`);
                        $shipment.innerHTML += ` №${shipment['ACCOUNT_NUMBER']}`;

                        if (shipment['RETURNED']) {
                            $shipment.innerHTML = `<span class="shipment-status--returned"> Возврат!</span>`;
                        }
                    }


                }

                function getHtml(type) {
                    switch (type) {
                        case 'shipments':
                            let htmlShipments = createElement('form', 'popup-shipment');
                            htmlShipments.id = 'shipments';
                            let tempHtmlShipments = `
                            <button class="popup-shipment__close" type="button"> <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#clip0_357_360)"><path d="M23.6646 20.0327L39.2398 4.45705C40.2535 3.44392 40.2535 1.80582 39.2398 0.792687C38.2267 -0.220446 36.5886 -0.220446 35.5755 0.792687L19.9998 16.3684L4.42457 0.792687C3.41096 -0.220446 1.77334 -0.220446 0.760206 0.792687C-0.253402 1.80582 -0.253402 3.44392 0.760206 4.45705L16.3354 20.0327L0.760206 35.6084C-0.253402 36.6215 -0.253402 38.2596 0.760206 39.2728C1.26511 39.7782 1.92899 40.032 2.59239 40.032C3.25579 40.032 3.91919 39.7782 4.42457 39.2728L19.9998 23.6971L35.5755 39.2728C36.0809 39.7782 36.7443 40.032 37.4077 40.032C38.0711 40.032 38.7345 39.7782 39.2398 39.2728C40.2535 38.2596 40.2535 36.6215 39.2398 35.6084L23.6646 20.0327Z" fill="black"/></g><defs><clipPath id="clip0_357_360"><rect width="40" height="40" fill="white"/></clipPath></defs></svg></button>
                            <h3>Создание отгрузки</h3>
                            <p>Служба доставки: </p>
                            <select name="deliveryId" class="popup-shipment__delivery">
                                <option></option>`;
                            for (const [deliveryId, property] of Object.entries(deliveryList)) {
                                tempHtmlShipments += `
                                <option value="${deliveryId}">${property['NAME']}</option>
                            `;
                            }
                            tempHtmlShipments += `
                            </select>
                            <div class="product">
                                <div class="product__name">Наименование товара</div>
                                <div class="product__values">Количество</div>
                            </div>`;
                            if (notShippedProducts) {
                                for (const [id, product] of Object.entries(notShippedProducts)) {
                                    tempHtmlShipments += `
                                    <label for="checkbox_${id}" class="product" id="${id}">
                                        <div class="product__name">${product['NAME']}</div>
                                        <div class="product__values">
                                            <input type="number" name="quantity[${id}] " value="${product['QUANTITY']}" max="${product['QUANTITY']}" class="product__quantity" data-name="${product['NAME']}">
                                            <input id="checkbox_${id}" type="checkbox" name="products[${id}]" value="${id}" class="product__checkbox">
                                        </div>
                                    </label>`;
                                }
                                tempHtmlShipments += '<button class="product__btn" type="submit">Создать отгрузку</button>';
                            }
                            htmlShipments.innerHTML = tempHtmlShipments;
                            return htmlShipments;

                        case 'returnProducts':
                            let htmlReturnProducts = createElement('form', 'popup-shipment');
                            htmlReturnProducts.id = 'returnProducts';
                            let tempHtmlReturnProducts = `
                            <button class="popup-shipment__close" type="button"> <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#clip0_357_360)"><path d="M23.6646 20.0327L39.2398 4.45705C40.2535 3.44392 40.2535 1.80582 39.2398 0.792687C38.2267 -0.220446 36.5886 -0.220446 35.5755 0.792687L19.9998 16.3684L4.42457 0.792687C3.41096 -0.220446 1.77334 -0.220446 0.760206 0.792687C-0.253402 1.80582 -0.253402 3.44392 0.760206 4.45705L16.3354 20.0327L0.760206 35.6084C-0.253402 36.6215 -0.253402 38.2596 0.760206 39.2728C1.26511 39.7782 1.92899 40.032 2.59239 40.032C3.25579 40.032 3.91919 39.7782 4.42457 39.2728L19.9998 23.6971L35.5755 39.2728C36.0809 39.7782 36.7443 40.032 37.4077 40.032C38.0711 40.032 38.7345 39.7782 39.2398 39.2728C40.2535 38.2596 40.2535 36.6215 39.2398 35.6084L23.6646 20.0327Z" fill="black"/></g><defs><clipPath id="clip0_357_360"><rect width="40" height="40" fill="white"/></clipPath></defs></svg></button>
                            <h3>Возврат товаров</h3>
                            <div class="product">
                                <div class="product__name">Наименование товара</div>
                                <div class="product__values">Количество</div>
                            </div>`;
                            if (products) {
                                for (const [id, product] of Object.entries(products)) {
                                    tempHtmlReturnProducts += `
                                    <label for="checkbox_${id}" class="product" id="${id}">
                                        <div class="product__name">${product['NAME']}</div>
                                        <div class="product__values">
                                            <input type="number" name="quantity[${id}] " value="${product['QUANTITY']}" max="${product['QUANTITY']}" class="product__quantity" data-name="${product['NAME']}">
                                            <input id="checkbox_${id}" type="checkbox" name="products[${id}]" value="${id}" class="product__checkbox">
                                        </div>
                                    </label>`;
                                }
                                tempHtmlReturnProducts += '<button class="product__btn" type="submit">Возврат</button>';
                            }

                            htmlReturnProducts.innerHTML = tempHtmlReturnProducts;
                            return htmlReturnProducts;
                    }


                }

                function openShipmentPopup(e) {
                    const type = e.target.dataset.type;
                    const html = getHtml(type);
                    const overlay = createElement('div', 'overlay');
                    document.body.append(html, overlay);
                    document.addEventListener('click', closeShipmentPopup);
                    const btn = document.getElementById(type).querySelector('.product__btn');
                    btn.removeEventListener('click', validateForm);
                    btn.addEventListener('click', validateForm);
                }

                function closeShipmentPopup(e) {
                    const popup = document.querySelector('.popup-shipment');
                    const overlay = document.querySelector('.overlay');
                    if (e.target.closest('.popup-shipment__close') || e.target.classList.contains('overlay')) {
                        popup.remove();
                        overlay.remove();
                        document.removeEventListener('click', closeShipmentPopup);
                    }
                }


                function validateForm(e) {
                    e.preventDefault();
                    const formType = e.target.closest('form').id;
                    const form = document.getElementById(formType)

                    const quantityInputs = form.querySelectorAll('.product__quantity');
                    for (const quantity of Object.values(quantityInputs)) {
                        let inputValue = parseInt(quantity.value);
                        let maxValue = parseInt(quantity.getAttribute('max'));
                        if (inputValue > maxValue) {
                            console.log(`${inputValue} больше чем ${maxValue}`)

                            return alert(`Проверьете введенное количество товара "${quantity.dataset.name}}"`);
                        }
                    }
                    let productIsSelected = false;
                    const checkboxes = form.querySelectorAll('.product__checkbox');
                    for (const checkbox of Object.values(checkboxes)) {
                        if (checkbox.checked) {
                            productIsSelected = true;
                            break;
                        }
                    }

                    let deliverySelected = true;

                    if (formType === 'shipments')
                        deliverySelected = form.querySelector('.popup-shipment__delivery').value;

                    if (productIsSelected && deliverySelected) {
                        sendAjax(formType);
                        return true;
                    } else if (productIsSelected === false) {
                        alert('Выберете товары');
                    } else if (!deliverySelected) {
                        alert('Выберете способ доставки');
                    }
                }

                async function sendAjax(formType) {
                    const url = `/ajax/additional_shipment_form.php`;
                    const form = document.getElementById(formType);
                    const formData = new FormData(form);
                    formData.append('sessid', BX.bitrix_sessid());
                    formData.append('orderId', orderId);
                    formData.append('action', formType);

                    if (formType === 'returnProducts') {
                        const response = await fetch(url, {
                            method: 'POST',
                            body: formData
                        });
                        try {
                            const data = await response.json();
                            console.log(data);
                            if (data === 'success') {
                                showLoader();
                                location.reload();
                            }
                        } catch (e) {
                            console.log(e);
                            alert('Ошибка');
                        }
                    }
                    if (formType === 'shipments') {
                        const response = await fetch(url, {
                            method: 'POST',
                            body: formData
                        });

                        try {
                            const data = await response.json();
                            console.log(data);
                            if (data === 'success') {
                                showLoader();
                                location.reload();
                            }
                        } catch (e) {
                            console.log(e);
                            alert('Ошибка');
                        }
                    }
                }

                function showLoader() {
                    const popup = document.querySelector('.popup-shipment');
                    popup.remove();
                    const overlay = document.querySelector('.overlay');
                    overlay.style.backgroundColor = '#fff';
                    BX.showWait();
                    document.getElementById('wait_bx-admin-prefix').innerText = '';
                }

                function createElement(tag, className) {
                    const element = document.createElement(tag);
                    if (className) element.className = className;
                    return element;
                }
            });

        </script>


        <?php
    }
}