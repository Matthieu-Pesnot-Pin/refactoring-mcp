<?php

namespace App\Http\Controllers;

use DB;
use Auth;
use Mail;
use Excel;
use Input;
use Schema;
use Request;
use App\User;
use Response;
use Exception;
use DateInterval;
use Carbon\Carbon;
use App\Tests\Test;
use App\Models\Size;
use App\Models\Check;
use App\Models\Order;
use SimpleXMLElement;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Country;
use App\Models\CronLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Barcode\Barcode;
use App\Models\DebugLog;
use App\Models\PackItem;
use App\Models\Preorder;
use App\Models\TraceLog;
use App\Enums\ClientType;
use App\Models\OrderItem;
use App\Models\Warehouse;
use App\Classes\CrossMessage;
use App\Enums\EdiProperty;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Helpers\EdiHelper;
use App\Models\CreditItem;
use App\Models\Permission;
use App\Models\ProductTag;
use \ConvertApi\ConvertApi;
use App\Enums\CarriersList;
use App\Enums\CronLogStatus;
use App\Models\FactoryOrder;
use App\Models\PreorderItem;
use App\Models\Segmentation;
use App\Enums\PreorderStatus;
use App\Enums\SpecialClients;
use App\Models\ClientComment;
use App\Models\PieceLocation;
use App\Models\SecurePayment;
use App\Models\ClientConsumer;
use App\Models\CreditCategory;
use App\Models\PieceInventory;
use App\Models\WarehouseStock;
use App\Classes\EDI\EDI_DESADV;
use App\Classes\EDI\EDI_PRICAT;
use App\Classes\FactoryOrders\FactoryOrdersUpdater;
use App\Classes\FactoryOrders\FactoryOrderMigration;
use App\Classes\Products\PackItemQuantities;
use App\Classes\Products\ProductQuantities;
use App\Models\ProductCategory;
use App\Models\ProgressTracker;
use App\Models\AppGlobalSetting;
use App\Models\ClientCollection;
use App\Models\ConsumerCategory;
use App\Models\OrderChange;
use App\Helpers\OrderHelper;
use App\Models\FactoryOrderChange;
use App\Models\OperationsIntOrder;
use App\Models\ProductsProductTag;
use App\Models\ProductSegmentation;
use App\Models\ImpactedPreorderItem;
use App\Models\PreorderItemByPieces;
use App\Models\UserBrandAssociation;
use App\Enums\AppGlobalSettingsNames;
use App\Enums\FactoryOrderProductModification;
use App\Enums\FactoryOrderStatus;
use App\Models\ProductQuantityChange;
use App\Models\UnreferencedOrderItem;
use App\Models\ClientAgentAssociation;
use App\Models\HistoriqueRepresentant;
use App\Enums\ProductQuantityChangeType;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\OrderController;
use App\Classes\Products\ProductXlsUpdater;
use App\Http\Controllers\AppBaseController;
use App\Repositories\OrderRepository;
use Maatwebsite\Excel\Writers\LaravelExcelWriter;
use App\Classes\Products\ProductQuantityCorrector;
use App\Classes\ShippingBo_Sftp;
use App\Classes\Xls\XlsGenerator;
use App\Helpers\AnticipatedInvoices;
use App\Helpers\InvoiceHelper;
use App\Helpers\PreorderHelper;
use App\Helpers\RetailBillingHelper;
use App\Helpers\GlobalHelper;
use App\Helpers\Tickets\Ticket_1097;
use App\Helpers\Tickets\Ticket_1360;
use App\Http\Controllers\ParcelTrackingController;
use App\Models\InvoiceItem;
use App\Models\PermissionUser;
use App\Models\ProductChange;
use Maatwebsite\Excel\Classes\LaravelExcelWorksheet;
use App\Helpers\Debug\DealWithTmpFileTrait;

class DebugController extends AppBaseController
{
  use DealWithTmpFileTrait;
  public function generalGet(InvoiceController $invoiceController)
  {
    $tracker = ProgressTracker::getTracker();
    $this->compareFactoryOrderDataTable();
    // $this->testShippingBoSftp();
    // dd(AnticipatedInvoices::checkAllocationSnapshotConsistency(AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_TEST));
    // AnticipatedInvoices::resetAllocationSnapshot(AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_TEST);
    // $scenario = Input::get("scenario");
    // if ($scenario == "scenario_1"){
    //   AnticipatedInvoices::getOrComputeAllocationSnapshot(AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_2026_05_18);
    // } else if ($scenario == "scenario_2"){
    //   return $this->exportAnticipatedInvoicesTransformableQuantities();
    // }
    // dd("done");
    // return $this->fixAnticipatedInvoicesPreordersQuantities();
    // $this->processAnticipatedInvoicesBatch();
    // dd("nothing to do"); 
  }

  public function testShippingBoSftp()
  {
    $order = Order::find(51820);
    $fileName = ShippingBo_Sftp::createCsvFile($order, "test.csv");
    dd($fileName);
  }

  public function retailBillingTest()
  {
    
    InvoiceController::invoiceRetailStoreUsers(RetailBillingHelper::BILLING_DAY_START);

    dd("here");

    $startDate = Carbon::parse('2026-04-01');
    $endDate = Carbon::parse('2026-04-15');
    $sales = RetailBillingHelper::fetchStoreSales(74, $startDate, $endDate);
    $commissionRate = 54;

    // $sales = RetailBillingHelper::keepOnlyPurchaseSales($sales);
    $sales = array_filter($sales, function($sale) {
      return starts_with($sale['created_at'], "2026-04-10");
    });

    echo "<pre>" . json_encode($sales, JSON_PRETTY_PRINT) . "</pre>";
    dd("");
    
    $singleItem = RetailBillingHelper::buildSingleUnreferencedItem($sales, $commissionRate, $startDate, $endDate);
    echo "<pre>" . json_encode($singleItem, JSON_PRETTY_PRINT) . "</pre>";
    dd();

    
    $startDate = Carbon::parse('2026-04-01');
    $endDate = Carbon::parse('2026-04-15');
    
    $stores = [
      [97, 54, "Ajaccio"],
      [94, 50, "Blagnac"],
      [48, 60, "Claye Souilly"],
      [86, 54, "Mont Saint Martin"],
      [88, 54, "Orleans"],
      [74, 54, "Rennes"],
      [59, 54, "Royan"],
      [87, 54, "Saint-Etienne"],
      [38, 54, "Toulouse"]
    ];

    $items = [];
    foreach ($stores as $store) {
      $storeId = $store[0];
      $commissionRate = $store[1];
      $data = RetailBillingHelper::fetchStoreSales($storeId, $startDate, $endDate);
      $singleItem = RetailBillingHelper::buildSingleUnreferencedItem($data, $commissionRate, $startDate, $endDate);
      $items[$storeId . " - " . $store[2]] = $singleItem;
    }


    dd($items);

  }

  public function processAnticipatedInvoicesBatch()
  {
    /** ANTICIPATED INVOICE PROCESS */
    $batchToProcess = AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_2026_05_18;
    // $batchToProcess = AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_TEST;
    // dd($batchToProcess);

    // 1 - Creation
    // AnticipatedInvoices::createAndInvoiceOrders($batchToProcess);
    // dd("anticipated invoices batch done");
    
    // 2 - Annulation des factures anticipées avec avoirs internes - uniquement  après envoi au factor
    // AnticipatedInvoices::cancelBatchInvoicesWithCredits($batchToProcess);
    // dd("anticipated invoices batch cancelled");

    // 3 - annulation des mouvements de stock créés par les commandes techniques.
    // AnticipatedInvoices::changeBatchOrdersStatuses($batchToProcess);
    // dd("anticipated invoices batch orders statuses changed");

    // 4 - Recalcul de quantity_ordered dans les précommandes originales pour éviter le double-comptage
    // Les commandes étant désormais en RECEIVED_KO (incluses dans ordersBaseQuery), on recalcule
    // quantity_ordered depuis les commandes réelles pour éviter le double-comptage avec les
    // précommandes recréées.
    // AnticipatedInvoices::updateBatchPreordersQuantities($batchToProcess);
    // dd("anticipated invoices batch preorders quantities updated");
    
    // 5 - Recréation des précommandes à partir des factures anticipées
    // AnticipatedInvoices::createPreordersFromBatchInvoices($batchToProcess);
    // dd("anticipated invoices batch preorders recreated");
  }

  /**
   * Correction rétroactive du double-comptage dans les précommandes des lots de facturation anticipée.
   *
   * Contexte : lors de l'étape changeBatchOrdersStatuses, cancel() remet quantity_ordered à 0 via
   * decreaseOrderedQuantity(). Or les précommandes recréées réservent déjà le stock — ce qui crée
   * un double-comptage. Ce correctif recalcule quantity_ordered depuis les commandes RECEIVED_KO
   * pour les lots 2026_04 et 2026_05_06, qui ont été traités avant l'ajout du recalcul automatique.
   */
  public function fixAnticipatedInvoicesPreordersQuantities()
  {
    // $batch = AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_2026_04;
    // $batch = AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_2026_05_06;
    AnticipatedInvoices::updateBatchPreordersQuantities($batch);
  }

  public function fixSinglePreorderItemQuantity($preorderItemId)
  {
    $item = \App\Models\PreorderItem::findOrFail($preorderItemId);

    $before = $item->quantity_ordered;
    $item->updateOrderedQuantity();
    $item = PreorderItem::find($item->id);
    $after = $item->quantity_ordered;

    $result = [
      'preorder_item_id'  => $item->id,
      'preorder_id'       => $item->preorder_id,
      'product_reference' => $item->product_reference,
      'total_quantity'    => $item->total_quantity,
      'quantity_ordered_before' => $before,
      'quantity_ordered_after'  => $after,
    ];

    DebugLog::logJson("fixSinglePreorderItemQuantity", $result);
    dd($result);
  }

  public function exportAnticipatedInvoicesTransformableQuantities()
  {
    $batchToProcess = AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_2026_05_18;
    // $batchToProcess = AnticipatedInvoices::ANTICIPATED_INVOICES_BATCH_TEST;
    return AnticipatedInvoices::exportTransformableQuantitiesByArrivals($batchToProcess);
  }

  /**
   * Test the autoReleasePreorders method
   * Used by cypress tests
   * @return void
   */
  public function autoReleasePreordersTest(){
    $startTime = microtime(true);
    PreorderHelper::autoReleasePreorders();
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;

    DebugLog::logJson('autoReleasePreorders_execution_time', [
      'execution_time_seconds' => round($executionTime, 4),
      'start_time' => date('Y-m-d H:i:s', (int)$startTime),
      'end_time' => date('Y-m-d H:i:s', (int)$endTime)
    ]);
    dd("transformations done");
  }

  public function addPermissionsToAllUsers(){
    
    $permissionsDefinitions = [
      ['name' => 'arrivals-index', 'display_name' => 'Accéder aux arrivages', 'section' => 'TJMAX', "category" => "Arrivages", "roleMustBeIn" => ['admin', 'accountant', 'agent', 'logistician', 'salesperson']],
      ['name' => 'catalogs-access', 'display_name' => 'Accéder aux catalogues', 'section' => 'TJMAX', "category" => "Catalogues", "roleMustBeIn" => ['admin', 'accountant', 'agent', 'logistician', 'salesperson']],
      ['name' => 'preorders-index', 'display_name' => 'Voir la table', 'section' => 'TJMAX', "category" => "Précommandes", "mustAlreadyHavePermission" => "order-index"],
    ];

    // Création des permissions si elles n'existent pas
    foreach ($permissionsDefinitions as $permission) {
      $existingPermission = Permission::where('name', $permission['name'])->first();
      if (!$existingPermission) {
        Permission::create($permission);
      }
    }

    $permissionNamesList = array_map(function ($permissionDefinition) {
      return $permissionDefinition['name'];
    }, $permissionsDefinitions);
    $permissionsModels = \App\Models\Permission::whereIn('name', $permissionNamesList)->get();
    $users = User::all();

    $addedPermissionsCount = 0;
    $tracker = ProgressTracker::getTracker();
    $tracker->setTargetCount(count($users));
    foreach ($users as $user) {
      $tracker->addCount();
      foreach ($permissionsDefinitions as $permissionDefinition) {
        $permissionModel = $permissionsModels->where('name', $permissionDefinition['name'])->first();
        if ($permissionModel == null) {
          continue;
        }
        $userMeetsConditions = true;

        // Vérification des rôles
        $rolesToCheck = isset($permissionDefinition['roleMustBeIn']) ? $permissionDefinition['roleMustBeIn'] : null;
        if ($rolesToCheck !== null && !$user->hasRole($rolesToCheck)) {
          $userMeetsConditions = false;
        }



        // Vérification des permissions pré-requises
        if ($userMeetsConditions && isset($permissionDefinition['mustAlreadyHavePermission'])) {
          if (!$user->is_allowed_to($permissionDefinition['mustAlreadyHavePermission'])) {
            $userMeetsConditions = false;
          }
        }

        if ($userMeetsConditions) {
          $permissionAlreadyAssigned = PermissionUser::where('user_id', $user->id)
            ->where('permission_id', $permissionModel->id)
            ->exists();
          
          if (!$permissionAlreadyAssigned) {
            PermissionUser::create([
              'user_id' => $user->id,
              'permission_id' => $permissionModel->id
            ]);
            $addedPermissionsCount++;
          }
        }
      }
    }

    dd("Done. Processed " . count($permissionsDefinitions) . " permissions and added $addedPermissionsCount missing permissions to " . count($users) . " users.");
  }

  public function updatePreorder015814AvailableAmount(){
    $reference = "PREOD-015814";
    $preorder = Preorder::where("reference", $reference)->first();

    if (!$preorder) {
      DebugLog::log("Update Available Amount $reference", "Preorder not found");
      dd("Preorder $reference not found");
    }

    $oldAmount = $preorder->available_amount;
    
    $report = [
      "reference" => $reference,
      "old_amount" => $oldAmount,
      "status" => "starting"
    ];
    DebugLog::logJson("Update Available Amount $reference", $report);

    try {
      $preorder->updateAvailableAmount();
      $newAmount = $preorder->available_amount;
      
      $report["new_amount"] = $newAmount;
      $report["status"] = "success";
      
      DebugLog::logJson("Update Available Amount $reference", $report);
      dd("Preorder $reference available amount updated: $oldAmount -> $newAmount");
    } catch (Exception $e) {
      $report["status"] = "error";
      $report["error"] = $e->getMessage();
      DebugLog::logJson("Update Available Amount $reference", $report);
      dd("Error updating preorder $reference: " . $e->getMessage());
    }
  }

  public function comparePreorderUpdateMethods(){
    $reference = "PREOD-015814";
    $preorder = Preorder::where("reference", $reference)->first();

    if (!$preorder) {
      dd("Preorder $reference not found");
    }

    $phpAmount = $preorder->remaining_items_price_available;

    // Detailed item check for SQL logic
    $rawItems = DB::select("
        SELECT 
            pi2.id as item_id,
            pi2.product_reference,
            pi2.total_quantity,
            pi2.product_unit_price,
            p.quantity_available as stock,
            (
                SELECT COALESCE(SUM(COALESCE(IF(o.status LIKE 'PREPARED%', oi.prepared_quantity, oi.total_quantity), 0)), 0)
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.preorder_id = pi2.preorder_id 
                AND o.status != 'CANCELLED'
                AND oi.product_reference = pi2.product_reference
                AND oi.deleted_at IS NULL
            ) as sql_ordered_qty,
            (
                SELECT GROUP_CONCAT(CONCAT(o.reference, ' (', o.status, ') : ', COALESCE(IF(o.status LIKE 'PREPARED%', oi.prepared_quantity, oi.total_quantity), 0)))
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.preorder_id = pi2.preorder_id 
                AND o.status != 'CANCELLED'
                AND oi.product_reference = pi2.product_reference
                AND oi.deleted_at IS NULL
            ) as orders_found
        FROM preorder_items pi2
        LEFT JOIN products p ON CONCAT(p.reference, '-', p.color_reference) = pi2.product_reference
        WHERE pi2.preorder_id = ?
        AND pi2.deleted_at IS NULL
    ", [$preorder->id]);

    $itemsDetails = [];
    foreach ($rawItems as $item) {
        $preorderItem = PreorderItem::find($item->item_id);
        $phpRemainingQty = $preorderItem ? $preorderItem->remaining_total_quantity : 0;
        
        $itemsDetails[] = [
            "ref" => $item->product_reference,
            "total_preo" => $item->total_quantity,
            "stock" => $item->stock,
            "sql_ordered" => $item->sql_ordered_qty,
            "php_remaining" => $phpRemainingQty,
            "price" => $item->product_unit_price,
            "orders" => $item->orders_found
        ];
    }

    // Simulate SQL query from PreorderController
    $preorderIdClause = "AND po.id IN ($preorder->id)";
    $paaPreorderIdAndClause = "AND paa.preorder_id IN ($preorder->id)";

    $sql = "SELECT SUM(items.dispo) as sql_amount FROM
                (
                    (SELECT
                    po.id as preorder_id,
                    po.reference,
                    pi2.product_reference,
                    LEAST (
                        GREATEST(
                            pi2.total_quantity - SUM(COALESCE(
                                IF(o.status LIKE 'PREPARED%', oi.prepared_quantity, oi.total_quantity), 
                                IF(o2.status LIKE 'PREPARED%', oi2.prepared_quantity, oi2.total_quantity), 
                                0
                            )), 
                            0
                        ),
                        GREATEST(p.quantity_available, 0)
                    ) * pi2.product_unit_price  as dispo
                    FROM preorders po
                    JOIN preorder_items pi2 ON pi2.preorder_id = po.id
                    LEFT JOIN orders o ON o.preorder_id = pi2.preorder_id AND o.status != 'CANCELLED'
                    LEFT JOIN order_items oi ON oi.order_id = o.id
                    AND oi.product_reference = pi2.product_reference
                    AND oi.deleted_at IS NULL
                    LEFT JOIN products p ON CONCAT(p.reference, '-', p.color_reference) = pi2.product_reference
                    LEFT JOIN impacted_preorder_items ipi ON ipi.preorder_item_id = pi2.id AND ipi.product_type = 'REFERENCED'
                    LEFT JOIN order_items oi2 ON oi2.id = ipi.order_item_id
                    AND oi2.deleted_at IS NULL
                    LEFT JOIN orders o2 ON o2.id = oi2.order_id
                    AND o2.status != 'CANCELLED'
                    WHERE po.status = 'ACTIVE'
                    AND pi2.deleted_at IS NULL
                    $preorderIdClause
                    GROUP by pi2.id, po.id
                ) UNION (
                    SELECT			
                        po.id as preorder_id,
                        po.reference,
                        CAST(pibp.product_reference AS CHAR),
                        LEAST (
                            GREATEST(
                                pibp.pieces_quantity - SUM(COALESCE(
                                    IF(o.status LIKE 'PREPARED%', oibp.prepared_quantity, oibp.pieces_quantity), 
                                    IF(o2.status LIKE 'PREPARED%', oibp.prepared_quantity, oibp.pieces_quantity), 
                                    0
                                )),
                                0
                            ), 
                            GREATEST(pki.pieces_available, 0)
                        ) * pibp.product_unit_price as dispo
                        FROM preorders po
                        JOIN preorder_items_by_pieces pibp ON pibp.preorder_id = po.id
                        LEFT JOIN orders o ON o.preorder_id = pibp.preorder_id AND o.status != 'CANCELLED'
                        LEFT JOIN order_items_by_pieces oibp ON oibp.order_id = o.id
                        AND oibp.deleted_at IS NULL
                        LEFT JOIN pack_items pki ON pki.id = pibp.pack_item_id
                        LEFT JOIN impacted_preorder_items ipi ON ipi.preorder_item_id = pibp.id AND ipi.product_type = 'PIECE'
                        LEFT JOIN order_items oibp2 ON oibp2.id = ipi.order_item_id
                        AND oibp2.deleted_at IS NULL
                        LEFT JOIN orders o2 ON o2.id = oibp2.order_id
                        AND o2.status != 'CANCELLED'
                        WHERE po.status = 'ACTIVE'
                        $preorderIdClause
                        GROUP BY po.id, pki.id
                )
            ) items";
    
    $sqlResult = DB::select($sql);
    $sqlAmount = $sqlResult[0]->sql_amount;

    // Also check if quantity_available is synced
    $outOfSyncProducts = [];
    foreach ($preorder->items as $item) {
        if ($item->product && $item->product->quantity_available != $item->product->total_stock) {
            $outOfSyncProducts[] = [
                "ref" => $item->product_reference,
                "quantity_available" => $item->product->quantity_available,
                "total_stock" => $item->product->total_stock
            ];
        }
    }

    $report = [
        "preorder_status" => $preorder->status,
        "reference" => $reference,
        "php_amount" => $phpAmount,
        "sql_amount" => $sqlAmount,
        "diff" => $phpAmount - $sqlAmount,
        "out_of_sync_products" => $outOfSyncProducts,
        "items_details" => $itemsDetails,
        "explanation" => "PHP filters out DRAFT and ODPXPR% orders. sql_ordered = quantity found by SQL. php_remaining = quantity PHP sees as still to order."
    ];

    DebugLog::logJson("Compare Update Methods $reference", $report);
    dd($report);
  }



  public function archivePreordersTests()
  {
    // dd(PreorderHelper::getActivePreordersWithOnlyCancelledProducts());
    PreorderHelper::archiveEmptyPreorders();
    dd("done");
    // $this->testStockAPI();
  }

  public function addProductsToCredits()
  {
    $products = [
      [ "reference" =>  "", "quantity" =>  0 ],
    ]; 

    $credit = Credit::find(20166);
    DB::transaction(function () use ($credit, $products) {
      foreach ($products as $product){
        $reference = $product["reference"];
        $quantity = $product["quantity"];
        $productDB = Product::whereRaw("CONCAT(reference, '-', color_reference) = ?", [$reference])->first();
        if ($productDB === null){
          throw new Exception("Product not found for reference " . $reference);
        }
        $lastInvoiceItem = InvoiceItem::join("invoices", "invoices.id", "=", "invoice_items.invoice_id")
          ->where("product_reference", $reference)
          ->where("client_id", $credit->client_id)
          ->orderBy("invoice_items.id", "desc")
          ->select("invoice_items.*")
          ->first();

        if ($lastInvoiceItem === null){
          dd($reference, $product);
          throw new Exception("Invoice item not found for product " . $reference);
        }
        CreditItem::create([
          "credit_id" =>  $credit->id,
          "order_item_id" =>  null,
          "invoice_item_id" =>  $lastInvoiceItem->id,
          "quantity" =>  $quantity,
          "unit_amount" =>  $productDB->unit_or_reduced_price,
          "credit_reason" =>  "",
          "category_id" =>  15
        ]);
      }
    });
  }

  public function testStockAPI()
  {
      $sku = "2433131_OW_L";
      $skuParts = explode("_", $sku);

      $reference = $skuParts[0];
      $color_reference = $skuParts[1];
      $size_label = $skuParts[2];

      /** @var Product $product */
      $product = Product::where('reference', $reference)->where('color_reference', $color_reference)->first();

      $result = [
          "reference" => $product->reference,
          "color_reference" => $product->color_reference,
          "orderable_quantity_by_pack" => $product->orderable_quantity_by_pack
      ];

      /** @var PackItem $packItem */
      $packItem = PackItem::where('product_id', $product->id)
          ->whereHas('size', function ($q) use ($size_label) {
              $q->where('label', $size_label);
          })->first();

      $currentOrders = [];

      if ($packItem) {
          $statuses = [
              OrderStatus::WAITING,
              OrderStatus::TO_VALIDATE,
              OrderStatus::PREPARATION,
              OrderStatus::PREPARING,
              OrderStatus::TO_VERIFY,
              OrderStatus::VERIFYING,
              OrderStatus::DELIVERED_INTEGRATED,
          ];

          $currentOrders = Order::whereIn('orders.status', $statuses)
              ->join('order_status_changes', 'order_status_changes.order_id', '=', 'orders.id')
              ->where(function ($query) use ($product, $packItem) {
                  // Orders with Packs (if pack contains the size)
                  if ($packItem->quantity > 0) {
                      $query->orWhereHas('items', function ($q) use ($product) {
                          $q->where('product_id', $product->id);
                      });
                  }
                  // Orders with Pieces (specific size)
                  $query->orWhereHas('itemsByPieces', function ($q) use ($packItem) {
                      $q->where('pack_item_id', $packItem->id);
                  });
              })
              ->where('client_id', SpecialClients::RETAIL_ONLINE)
              ->groupBy('orders.id')
              ->orderBy('orders.created_at', 'desc')
              ->select('orders.*', DB::raw('GROUP_CONCAT(CONCAT(order_status_changes.created_at, "___", order_status_changes.status_after) ORDER BY order_status_changes.created_at DESC) as statuses_history'))
              ->get()
              ->map(function ($order) {
                  return [
                      "reference" => $order->reference,
                      "created_at" => $order->created_at->format('Y-m-d H:i:s'),
                      "statuses_history" => array_map(function ($statusChangeRaw) {
                        $statusChangeRaw = explode("___", $statusChangeRaw);
                        return [
                          "created_at" => $statusChangeRaw[0],
                          "status" => $statusChangeRaw[1],
                        ];
                      }, explode(",", $order->statuses_history)),
                      "rawStatus" => $order->status,
                      "status" => OrderStatus::getDict($order->status),
                  ];
              });
      }

      $result['ongoing_orders'] = $currentOrders;

      dd($result);
  }
  
  private function ticket_1353_cancelInvoicesWithCredit(){
    return Ticket_1360::getCreditsXls();
  }

  public function updatePreorderAvailableAmount($limit){
    $preorders = Preorder::where("status", PreorderStatus::ACTIVE)
    ->orderBy("date", "desc")
    ->limit($limit)->get();
    $tracker = ProgressTracker::getTracker();
    $tracker->setTargetCount(count($preorders));
    $tracker->setClientMessage("Updating preorders available amount / " . count($preorders));
    foreach ($preorders as $preorder){
      $tracker->addCount();
      $preorder->updateAvailableAmount();
    }
    dd("Preorders available amount updated");
  }

  public function resendInvoiceEmails(){
    // AppGlobalSetting::setSetting(AppGlobalSettingsNames::INVOICES_MAILS_ALREADY_RESENT, [], true);
    $batchSize = 100;
    $alreadyResentInvoiceIds = AppGlobalSetting::getSetting(AppGlobalSettingsNames::INVOICES_MAILS_ALREADY_RESENT);
    $invoicesIds = AppGlobalSetting::getSetting(AppGlobalSettingsNames::INVOICES_MAILS_TO_RESEND);
    $invoiceIds = array_filter($invoicesIds, function ($invoiceId) use ($alreadyResentInvoiceIds){
      return !in_array($invoiceId, $alreadyResentInvoiceIds);
    });

    $totalLeft = count($invoiceIds);

    $invoiceIds = array_slice($invoicesIds, count($alreadyResentInvoiceIds), $batchSize);

    AppGlobalSetting::setSetting(AppGlobalSettingsNames::INVOICES_MAILS_ALREADY_RESENT, array_merge($alreadyResentInvoiceIds, $invoiceIds), true);

    if (count($invoiceIds) === 0){
      dd("No invoices to resend in batch");
    }
    $invoices = Invoice::whereIn("id", $invoiceIds)
      ->whereNotIn("client_id", [SpecialClients::PXPR_ES, SpecialClients::PROJECT_X_PARIS_RETAIL])
      ->get();
    if (count($invoices) === 0){
      dd("No invoices found");
    }
    try {
      $result = EmailController::sendInvoicesList($invoices);
      AppGlobalSetting::setSetting(AppGlobalSettingsNames::INVOICES_MAILS_ALREADY_RESENT, array_merge($alreadyResentInvoiceIds, $invoiceIds), true);
      DebugLog::logJson("invoices resent", [
        "result" => $result,
        "ids" => $invoices->map(function ($invoice) { return $invoice->id; }),
      ]);
    } catch (Exception $e){
      dd("Error sending invoices list: " . $e->getMessage());
    }


    dd("BATCH RESENT " . ($totalLeft - count($invoiceIds)) . " invoices left");

    // $invoices = Invoice::join("clients", "clients.id", "=", "invoices.client_id")
    //   ->whereRaw("invoices.reference NOT LIKE 'GIFT%'")
    //   ->whereRaw("invoices.reference NOT LIKE 'FAPXP%'")
    //   ->whereRaw("invoices.reference NOT LIKE 'FAPROJ%'")
    //   ->where("clients.client_type", ClientType::MULTI)
    //   ->where("invoices.created_at", ">=", "2025-09-12")
    //   // ->where("invoices.created_at", "<", "2025-09-13")
    //   ->select("invoices.*")
    //   ->get();
    // DebugLog::logJson("invoices to which an email should be sent", $invoices->map(function ($invoice) {
    //   return $invoice->id;
    // }));
    // $tracker = ProgressTracker::getTracker();
    // $invoices = $invoices->filter(function ($invoice) use ($tracker) {
    //   $tracker->addCount();
    //   return $invoice->balance > 0;
    // });
    // DebugLog::logJson("invoices to which an email should be sent with positive balance", $invoices->map(function ($invoice) {
    //   return $invoice->id;
    // }));
    // dd(json_encode($invoices->map(function ($invoice) {
    //   return $invoice->reference;
    // })));
  }

  public function ticket_1322_permissions_migration()
  {
    $report = [];

    DB::transaction(function () use (&$report) {
        // Mapping des anciens droits vers les nouveaux
        $permissionsMapping = [
            'invoice-index' => [
                'invoices-index-pxpr',
                'invoices-index-pxpr-es',
                'invoices-index-faproj',
                'invoices-index-ret',
                'invoices-index-pjs',
                'invoices-index-giftcard',
                'invoices-index-return'
            ],
            'credit-index' => [
                'credit-index-silverGift',
                'credits-index-pxpr',
                'credits-index-faproj',
                'credits-index-return'
            ],
            'order-index' => [
                'order-index-pxpr'
            ]
        ];

        $report['start_time'] = Carbon::now()->toDateTimeString();
        $report['mappings'] = [];

        // Pour chaque mapping ancien → nouveaux droits
        foreach ($permissionsMapping as $oldPermission => $newPermissions) {
            $mappingReport = [
                'old_permission' => $oldPermission,
                'new_permissions_count' => count($newPermissions),
                'new_permissions' => $newPermissions,
                'users_found' => 0,
                'attributions' => [],
                'already_assigned' => [],
                'errors' => []
            ];

            $oldPermissionId = Permission::where('name', $oldPermission)->first()->id;
            // 1. Récupérer les PermissionUser avec l'ancien droit
            $permissionUsers = PermissionUser::where('permission_id', $oldPermissionId)->get();

            $mappingReport['users_found'] = $permissionUsers->count();

            // 2. Pour chaque utilisateur, attribuer les nouveaux droits
            foreach ($permissionUsers as $permissionUser) {
                $user = $permissionUser->user;
                if (!$user){
                    $mappingReport['errors'][] = "Utilisateur non trouvé pour l'ID " . $permissionUser->user_id;
                    continue;
                }

                foreach ($newPermissions as $newPermissionName) {
                    // Vérifier si le nouveau droit existe
                    $newPermission = Permission::where('name', $newPermissionName)->first();

                    if (!$newPermission) {
                        $mappingReport['errors'][] = "Nouveau droit '{$newPermissionName}' non trouvé";
                        continue;
                    }

                    // Vérifier si l'utilisateur n'a pas déjà ce droit
                    $existingPermissionUser = PermissionUser::where('user_id', $user->id)
                        ->where('permission_id', $newPermission->id)
                        ->first();

                    if (!$existingPermissionUser) {
                        // Insérer la nouvelle PermissionUser
                        PermissionUser::create([
                            'user_id' => $user->id,
                            'permission_id' => $newPermission->id
                        ]);

                        $mappingReport['attributions'][] = [
                            'user_id' => $user->id,
                            'user_name' => $user->name,
                            'permission' => $newPermissionName
                        ];
                    } else {
                        $mappingReport['already_assigned'][] = [
                            'user_id' => $user->id,
                            'user_name' => $user->name,
                            'permission' => $newPermissionName
                        ];
                    }
                }
            }

            $report['mappings'][] = $mappingReport;
        }

        $report['end_time'] = Carbon::now()->toDateTimeString();
        $report['total_mappings'] = count($permissionsMapping);

        // Calculer les totaux
        $totalAttributions = 0;
        $totalAlreadyAssigned = 0;
        $totalErrors = 0;

        foreach ($report['mappings'] as $mapping) {
            $totalAttributions += count($mapping['attributions']);
            $totalAlreadyAssigned += count($mapping['already_assigned']);
            $totalErrors += count($mapping['errors']);
        }

        $report['summary'] = [
            'total_attributions' => $totalAttributions,
            'total_already_assigned' => $totalAlreadyAssigned,
            'total_errors' => $totalErrors,
            'total_users_processed' => array_sum(array_column($report['mappings'], 'users_found'))
        ];

        $report['success'] = ($totalErrors === 0);
    });

    // Enregistrer le rapport avec DebugLog
    DebugLog::logJson('permissions_migration_report', $report);

    dd("DONE - Report logged with DebugLog::logJson");
  } 


  public function ticket_1274_remapping_cuts(){
    if (Input::get("scenario") == "scenario_1"){ // From many cuts with same name to one cut by name
      $referenceCutsByName = [];
      $cuts = ProductCategory::getCuts(0);
      $cutsNames = $cuts->pluck("name", "id");
      foreach ($cutsNames as $cutName){
        if (!isset($referenceCutsByName[$cutName])){
          $firstCut = ProductCategory::where("level", ProductCategory::LEVEL_CUT)
            ->where("deprecated", 0)
            ->where("name", $cutName)->orderBy("id", "asc")->first();
          $referenceCutsByName[$cutName] = $firstCut->id;
        }
      }
      $tracker = ProgressTracker::getTracker();
      $products = Product::join("product_categories as cut", "cut.id", "=", "products.cut")
        ->where("cut", "!=", 0)->whereNotNull("cut")
        ->select("products.id", "products.name", "cut.name as cut_name")
        ->get();
      $tracker->setTargetCount(count($products));
      $tracker->setClientMessage("Cuts from many to one Products collected / " . count($products));
      foreach ($products as $product){
        $tracker->addCount();
        $product->cut = $referenceCutsByName[$product->cut_name];
        $product->save();
      }
      dd("Cut remmaped from many to one");
    } else if (Input::get("scenario") == "scenario_2"){ // From one cut to many cuts 
      $modifiedProducts = [];
      $referenceCutsByNameAndSubcategory = [];
      $cuts = ProductCategory::getCuts();
      foreach ($cuts as $cut){
        if (!isset($referenceCutsByNameAndSubcategory[$cut->parent_id])){
          $referenceCutsByNameAndSubcategory[$cut->parent_id] = [];
        }
        $referenceCutsByNameAndSubcategory[$cut->parent_id][$cut->name] = $cut->id;
      }

      $products = Product::join("product_categories as cut", "cut.id", "=", "products.cut")
        ->select(["products.*", "cut.name as cut_name"])
        ->get();

      $tracker = ProgressTracker::getTracker();
      $tracker->setTargetCount(count($products));
      $tracker->setClientMessage("Cuts from one to many Products collected / " . count($products));

      foreach ($products as $product){
        $tracker->addCount();
        if (!isset($referenceCutsByNameAndSubcategory[$product->subcategory][$product->cut_name])){
          dd([
            'referenceCutsByNameAndSubcategory' => $referenceCutsByNameAndSubcategory,
            'product->subcategory' => $product->subcategory,
            'product->cut_name' => $product->cut_name,
            'product' => $product,
          ]);
        }
        if ($product->cut != $referenceCutsByNameAndSubcategory[$product->subcategory][$product->cut_name]){
          $modifiedProducts[] = $product;
        }
        $product->cut = $referenceCutsByNameAndSubcategory[$product->subcategory][$product->cut_name];
        $product->save();
      }
      dd("Cut remmaped from one to many", $modifiedProducts);
    } else if (Input::get("scenario") == "scenario_3"){ // Current state
      // Gather all cuts used by products and get how many ids by cut name. 
      // If at least one name has two ids, then current state is many cuts by name
      // Else, current state is one cut by name.
      // Attention : considered cuts must be used by a product.

      $products = Product::join("product_categories as cut_cat", "cut_cat.id", "=", "products.cut")
        ->whereNotNull("cut")
        ->where("cut", "!=", 0)
        ->whereRaw("cut_cat.parent_id != products.subcategory")
        ->select("cut_cat.*", "products.id", "products.subcategory")
        ->get();

      if (count($products) !== 0){
        dd("Current state is one cut by name");
      } else {
        dd("Current state is many cuts by name");
      }
    }
  }

  public function ticket_1269_bug_impact_precommandes_cl3102(){

    $createdImpacts = [
      "done" => [],
      "errors" => [],
      "warnings" => [],
    ];
    DB::transaction(function () use (&$createdImpacts){

      $entriesToCorrect = DB::select(
        "SELECT 
            o.id as order_id,
            p.id as preorder_id,
            o.reference, 
            o.created_at, 
            COALESCE(oi.prepared_quantity, oi.total_quantity) as ordered_quantity_without_impact,
            oi.id as order_item_id,
            pt.id as preorder_item_id,
            pt.total_quantity as preordered_quantity,
            (pt.total_quantity - pt.quantity_ordered) as remaining_preordered_quantity,
            o.id,
            oi.product_reference,
            oi.product_id
            -- group_concat(DISTINCT oi.product_reference),
            -- group_concat(DISTINCT oi.product_id),
              -- count(*)
          FROM preorders p
          JOIN preorder_items pt on pt.preorder_id = p.id
          JOIN order_items oi on oi.product_id = pt.product_id
          JOIN orders o on o.id = oi.order_id
          LEFT JOIN impacted_preorder_items ipi on ipi.preorder_item_id = pt.id and ipi.product_type='REFERENCED' AND ipi.order_item_id = oi.id
          WHERE o.client_id = 3102
          AND p.status != 'FILED'
          AND p.client_id = 3102
          AND o.created_at > '2025-07-01'
          AND o.sous_client_id != p.sous_client_id
          AND ipi.id is null"
        );
        foreach ($entriesToCorrect as $entry){
          $impact = ImpactedPreorderItem::where("order_item_id", $entry->order_item_id)
            ->where("preorder_item_id", $entry->preorder_item_id)
            ->where("product_type", ProductType::REFERENCED)
            ->first();

          if ($impact){
            $createdImpacts["errors"][] = "Existing impact : " . $impact->id;
            continue;
          }

          $preorder = Preorder::find($entry->preorder_id);
          $order = Order::find($entry->order_id);
          $product = Product::find($entry->product_id);
          $orderItem = OrderItem::where("id", $entry->order_item_id)->withTrashed()->first();
          $preorderItem = PreorderItem::find($entry->preorder_item_id);
          $remainingQuantity = $preorderItem->remaining_total_quantity;

          if (!$orderItem){
            dd($entry);
          }

          if ($remainingQuantity <= 0){
            $createdImpacts["warnings"][] = [
              "message" => "Remaining quantity is 0 or negative for the product " . $product->link . " (id : " . $product->id . ")",
              "product" => $product->link,
              "remainingQuantity" => $remainingQuantity,
              "orderItem" => $orderItem->order->link,
              "preorderItem" => $preorderItem->preorder->link,
              "order" => $order->link,
              "preorder" => $preorder->link,
            ];
            continue;
          }
          $impactingQuantity = $entry->ordered_quantity_without_impact;
          if ($remainingQuantity < $impactingQuantity){
            $createdImpacts["warnings"][] = [
              "message" => "Ordered quantity is greater than remaining quantity - quantity adjusted to " . $remainingQuantity,
              "product" => $product->link,
              "remainingQuantity" => $remainingQuantity,
              "impactingQuantity" => $impactingQuantity,
              "orderItem" => $orderItem->order->link,
              "preorderItem" => $preorderItem->preorder->link,
              "order" => $order->link,
              "preorder" => $preorder->link,
            ];
            $impactingQuantity = $remainingQuantity;
          }

          $impact = ImpactedPreorderItem::create([
            'preorder_item_id' => $entry->preorder_item_id,
            'order_item_id' => $entry->order_item_id,
            'quantity_before' => $preorderItem->remaining_total_quantity,
            'quantity_after' => $preorderItem->remaining_total_quantity - $impactingQuantity,
            'total_deducted_quantity' => $impactingQuantity,
            'product_type' => ProductType::REFERENCED,
          ]);
          $createdImpacts["done"][] = $impact;
          $preorder->addHistory("Ajout manuel d'une déduction depuis la commande " . $order->link . " pour le produit " . $product->link . " : -" . $impactingQuantity);
        }
        DebugLog::logJson("ticket_1269_bug_impact_precommandes_cl3102 createdImpacts", $createdImpacts);
      });

      dd("DONE");
  }

  public function ticketAvailableQuantitiesPXPRES(){
    $quantities = PackItemQuantities::getQuantitiesSummary();
    $totalByProductId = [];
    foreach ($quantities as $quantity){
      if (!isset($totalByProductId[$quantity->product_id])){
        $totalByProductId[$quantity->product_id] = 0;
      }
      if ($quantity->orderable_quantity < 0){
        dd($quantity);
      }
      $totalByProductId[$quantity->product_id] += $quantity->orderable_quantity;
    }
    foreach ($totalByProductId as $productId => $total){
     if ($total < 0){
      dd($productId, $total);
     }
    }

    dd("DONE");

    $product = Product::find(1);

    $id = 1;
    $ids = [13718, 13716, 13715, 13714, 13713, 13712, 13711, 13710, 13709, 13708, 13707, 13706, 13705, 13704, 13703, 13702, 13701, 13700, 13699, 13698, 13697, 13696, 13695, 13694, 13693, 13692, 13691, 13690, 13689, 13688, 13687, 13686, 13685, 13684, 13683, 13682, 13681, 13680, 13679, 13678, 13677, 13676, 13675, 13674, 13673, 13672, 13671, 13670, 13669, 13668, 13667, 13666, 13665, 13664, 13663, 13662, 13661, 13660, 13659, 13658, 13657, 13656, 13655, 13654, 13653, 13652, 13651, 13650, 13649, 13648, 13647, 13646, 13645, 13644, 13643, 13642, 13641, 13640, 13639, 13638, 13637, 13636, 13635, 13634, 13633, 13632, 13631, 13630, 13629, 13628, 13627, 13626, 13625, 13624, 13623, 13622, 13621, 13620, 13619, 13618, 13617, 13616, 13615, 13614, 13613, 13612, 13611, 13610, 13609, 13608, 13607, 13606, 13605, 13604, 13603, 13602, 13601, 13600, 13599, 13598, 13597, 13596, 13595, 13594, 13593, 13592];
    $packItemsIds = [159099, 158898, 158897, 158896, 158895, 158859, 158858, 158857, 158856, 158855, 158854, 158853, 158852, 158851, 158850, 158822, 158821, 158820, 158819, 158818, 158817, 158816, 158815, 158814, 158813, 158812, 158811, 158810, 158809, 158808, 158807, 158806, 158805, 158804, 158803, 158802, 158801, 158800, 158799, 158798, 158797, 158796, 158795, 158794, 158793, 158792, 158791, 158790, 158789, 158788, 158787, 158786, 158785, 158784, 158783, 158782, 158781, 158780, 158779, 158778, 158777, 158776, 158775, 158774, 158773, 158772, 158771, 158770, 158769, 158768, 158767, 158766, 158765, 158764, 158763, 158762, 158761, 158760, 158759, 158758, 158757, 158756, 158755, 158754, 158753, 158752, 158751, 158750, 158749, 158748, 158747, 158746, 158745, 158744, 158743, 158742, 158741, 158740, 158739, 158738, 158737, 158736, 158735, 158734, 158733, 158732, 158731, 158730, 158729, 158728, 158727, 158726, 158725, 158724, 158723, 158722, 158721, 158720, 158719, 158718, 158717, 158716, 158715, 158714, 158713, 158712, 158711, 158710, 158709];
    $clientId = false;
    $sellingPointId = false;

    // dd([
    //   "pack" => $product->getQuantitiesSummary($clientId, $sellingPointId),
    //   "packItems" => $product->getPackItemsQuantitiesSummary($clientId, $sellingPointId)
    // ]);
    dd([
      "pack" => ProductQuantities::getQuantitiesSummary($ids, $clientId, $sellingPointId),
      "packItems" => PackItemQuantities::getQuantitiesSummary($packItemsIds, $clientId, $sellingPointId)
    ]);



    $quantities = ProductQuantities::getQuantitiesSummary($ids, $clientId, $sellingPointId);
    // $orderablePackQuantity = $quantities->orderable_pack_quantity;
    // $remainingPreorderPackQuantity = $quantities->remaining_preorder_pack_quantity;
    // $quantityAvailable = min($orderablePackQuantity + $remainingPreorderPackQuantity, $quantities->total_pack_stock);

    dd($quantities);

  }

  public function getOverdueInvoices2025(){
    $startDate = '2024-01-01 00:00:00';
    $endDate = '2024-03-31 23:59:59';
    
    $invoices = Invoice::whereRaw('DATE_ADD(created_at, INTERVAL payment_period DAY) BETWEEN ? AND ?', [$startDate, $endDate])
      ->where(function ($query) {
        $query->where("reference", "like", "FA-%")
        ->orWhere("reference", "like", "FAPXPR-%");
      })
      ->whereNotIn("client_id", [
        SpecialClients::PJ_STORE, 
        SpecialClients::PROJECT_X_PARIS_RETAIL,
        SpecialClients::PXPR
      ])
      ->select([
        "*",
        DB::raw('DATE_ADD(created_at, INTERVAL payment_period DAY) as due_date')
      ])
      ->orderBy('created_at', 'asc')
      ->get();
      
    $xlsGenerator = new XlsGenerator([
      "Ref" => "reference",
      "Client" => function ($invoice){
        return "CL-" . str_pad($invoice->client_id, 4, "0", STR_PAD_LEFT);
      },
      "Date" => "created_at",
      "Date d'échéance" => "due_date",
      "Montant" => "total_amount",
      "Solde" => "balance",
      "Paiements" => function ($invoice){
        return implode(", ", array_map(function ($payment){
          return $payment->date . " ($payment->reference)";
        }, $invoice->allPayments)) ;
      }
      
    ]);

    $xlsGenerator->generate($invoices);
    return $xlsGenerator->saveToFile("invoices_overdue_2024_Q1");
  }

  public function getPaidInvoices2025(){
    $startDate = "2025-01-01 00:00:00";
    $endDate = "2025-03-31 23:59:59";
    
    // Regular payments
    $regularPaymentInvoices = Invoice::select(['invoices.*', DB::raw('payments.date as paid_at'), 'payments.reference as payment_reference'])
      ->join('payment_invoices', 'invoices.id', '=', 'payment_invoices.invoice_id')
      ->join('payments', 'payments.id', '=', 'payment_invoices.payment_id')
      ->where('payments.date', '>=', $startDate)
      ->where('payments.date', '<=', $endDate)
      // ->limit(3)
      ->get();

    // Secure payments
    $securePaymentInvoices = Invoice::select(['invoices.*', DB::raw('secure_payments.paid_at as paid_at'), 'secure_payments.token as payment_reference'])
      ->join('secure_payments_invoices', 'invoices.id', '=', 'secure_payments_invoices.invoice_id')
      ->join('secure_payments', 'secure_payments.id', '=', 'secure_payments_invoices.secure_payment_id')
      ->where('secure_payments.paid_at', '>=', $startDate)
      ->where('secure_payments.paid_at', '<=', $endDate)
      ->where('secure_payments.status', 'PAID')
      // ->limit(3)
      ->get();

    // Credits
    // $creditInvoices = Invoice::select(['invoices.*', DB::raw('('credits as paid_at')])
    //   ->join('credits_invoices_payment', 'invoices.id', '=', 'credits_invoices_payment.invoice_id')
    //   ->join('credits', 'credits.id', '=', 'credits_invoices_payment.credit_id')
    //   ->where('credits.created_at', '>=', $startDate)
    //   ->where('credits.created_at', '<=', $endDate)
      // ->limit(3)
    //   ->get();

    // Checks
    $checkInvoices = Invoice::select(['invoices.*', DB::raw('check_presentations.date as paid_at'), 'check_presentations.reference as payment_reference'])
      ->join('check_invoices', 'invoices.id', '=', 'check_invoices.invoice_id')
      ->join('checks', 'checks.id', '=', 'check_invoices.check_id')
      ->join('check_associations', 'checks.id', '=', 'check_associations.check_id')
      ->join('check_presentations', 'check_associations.check_presentation_id', '=', 'check_presentations.id')
      ->where('check_presentations.date', '>=', $startDate)
      ->where('check_presentations.date', '<=', $endDate)
      // ->limit(3)
      ->get();


    // Exchange bills
    $exchangeBillInvoices = Invoice::select(['invoices.*', DB::raw('exchange_bills.created_at as paid_at'), 'exchange_bills.reference as payment_reference'])
      ->join('exchange_bill_invoices', 'invoices.id', '=', 'exchange_bill_invoices.invoice_id')
      ->join('exchange_bills', 'exchange_bills.id', '=', 'exchange_bill_invoices.exchange_bill_id')
      ->where('exchange_bills.created_at', '>=', $startDate)
      ->where('exchange_bills.created_at', '<=', $endDate)
      // ->limit(3)
      ->get();

 

    // Merge all invoices and remove duplicates
    $allInvoices = [];
    foreach ($regularPaymentInvoices as $invoice) {
      $allInvoices[] = $invoice;
    }
    foreach ($securePaymentInvoices as $invoice) {
      $allInvoices[] = $invoice;
    } 
    foreach ($checkInvoices as $invoice) {
      $allInvoices[] = $invoice;
    }
    foreach ($exchangeBillInvoices as $invoice) {
      $allInvoices[] = $invoice;
    }

    $allInvoices = collect($allInvoices)->unique('id');

    $xlsGenerator = new XlsGenerator([
      "Ref" => "reference",
      "Date" => "created_at",
      "Date d'échéance" => "due_date",
      "Montant" => "total_amount",
      "Solde" => "balance",
      "Paiements" => function ($invoice){
        return $invoice->paid_at . " (" . $invoice->payment_reference . ")";
        $payments = array_filter($invoice->allPayments, function ($payment){
          // Filter out payments where reference starts with "AV"
          if (isset($payment->reference)) {
            return strpos($payment->reference, 'AV') !== 0;
          }
          return true;
        });
        return implode(", ", array_map(function ($payment){
          if (get_class($payment) == SecurePayment::class) {
            $paidAt = $payment->paid_at;
          } else {
            $paidAt = $payment->date;
          }
          return $paidAt . " ($payment->reference)";
        }, $payments)) ;
      }
      
    ]);

    $xlsGenerator->generate($allInvoices);
    return $xlsGenerator->saveToFile("invoices_paid_2025_Q1");
  }

  public function assignCreditsUsers(){
    $report = [
      "usersFound" => [],
      "usersNotFound" => []
    ];
    $credits = Credit::whereNull("created_by")
      ->select(["credits.*", "user_id"])
      ->join("credit_changes", "credits.id", "=", "credit_changes.credit_id")
      ->where("credit_changes.changement", "like", "Création de l'avoir :%")
      ->orderBy("credits.id", "desc")
      ->get();

    $tracker = ProgressTracker::getTracker();
    // $tracker->setUseFileStorage(true);
    $tracker->setTargetCount(count($credits));

    // DB::transaction(function () use (&$report, &$tracker, $credits) {
      foreach ($credits as $credit){
        $credit->created_by = $credit->user_id;
        $credit->save();
        $tracker->addCount();
      }
      $usersFoundRequest = "SELECT * FROM credits WHERE id IN (" . implode(",", $report["usersFound"]) . ")";
      $usersNotFoundRequest = "SELECT * FROM credits WHERE id IN (" . implode(",", $report["usersNotFound"]) . ")";
      
      $report["usersFoundRequest"] = $usersFoundRequest;
      $report["usersNotFoundRequest"] = $usersNotFoundRequest;
      DebugLog::logJson("assignCreditsUsers", $report);      
    // });
    dd($report);
  }

  public function removeProductsMovements(){
    DB::transaction(function () {
      $orderId = 100413;
      $productsChanges = ProductQuantityChange::where("order_id", $orderId)->whereNotNull('product_id')->get();
      $packItemsChanges = ProductQuantityChange::where("order_id", $orderId)->whereNotNull('pack_item_id')->get();
      
      $productIds = $productsChanges->pluck('product_id')->filter()->unique()->toArray();
      $packItemIds = $packItemsChanges->pluck('pack_item_id')->filter()->unique()->toArray();
      
      $products = Product::whereIn('id', $productIds)->get();
      $packItems = PackItem::whereIn('id', $packItemIds)->get();

      foreach ($productsChanges as $productChange){
        $product = $products->where('id', $productChange->product_id)->first();
        $quantity = $productChange->quantity_before - $productChange->quantity_after;
        $product->mainWarehouseStock->increaseQuantity($quantity, ProductQuantityChangeType::ORDER, $orderId);
      }

      foreach ($packItemsChanges as $packItemChange){
        $packItem = $packItems->where('id', $packItemChange->pack_item_id)->first();
        $quantity = $packItemChange->quantity_before - $packItemChange->quantity_after;
        $packItem->mainWarehouseStock->increaseQuantity($quantity, ProductQuantityChangeType::ORDER, $orderId);
      }
    });
  }

  public function ticket_1097_product_categories_migration(){
    Ticket_1097::migrate();
  }

    // $client = Client::find(1);
    // $client->edi_gln_json;
    // echo '<pre>'; print_r($client->edi_gln_json); echo '</pre>';
    // $client->edi_gln_json = json_encode([
    //   EdiProperty::GLOBAL_EDI_NUMBER => "123456789",
    // ]);
    // $client->save();
    // $order = Order::find(61302);
    // dd($order->hasImpactedPreorders);
    // $address = Address::find(14238);
    // dd($address->splittedStreet);

  public function debugGeneralView()
  {
    return view('debug.general');
  }

  public function addPermissionToAllUsers($permissionId, $exceptions = []){
    $users = User::all();
    $tracker = ProgressTracker::getTracker();
    $tracker->setTargetCount(count($users));
    foreach ($users as $user) {
      $tracker->addCount();
      if (in_array($user->id, $exceptions)) continue;
      $user->addPermission($permissionId);
    }
  }

  public function ediDesadvTest(){
    $scenario = Input::get("scenario");
    $order = Order::find(93541);
    $package = $order->packages->first();
    if ($scenario == "scenario_1"){
      $order->TEST_DESADV_spreadItemsInPackages();
    } else if ($scenario == "scenario_2"){
      $desadv = new EDI_DESADV($order);
      // dd($desadv->parseContent(true));
      $desadv->sendToTx2(true);
      echo "Sent to Tx2";

      // $order->TEST_DESADV_spreadItemsInPackages();
      // $order->assignDesadvIdToPackages();
      // dd($order->packages);
    } else if ($scenario == "scenario_3"){
      $desadv = new EDI_DESADV($order); 
      dd($desadv->getFilePath());
    } else {
      // echo $order->packages[0]->getHtmlBarcode();
      // $generator->createBarcodeFile($filePath, $code);
      // echo "<img src='/img/$fileName' alt='Code-barres'>";

    }
  }

  public function ediPricatTest(){
    $scenario = Input::get("scenario");
    $clientId = Input::get("client_id", 1);
    $client = Client::find($clientId);
    $items = PackItem::with('product', 'size')->inRandomOrder()->orderBy("id", "desc")->limit(100)->get();
    $pricat = $client->buildPricatContext($items);

    if ($scenario == "scenario_1"){
      // Inspecter le contenu parsé sans envoi
      $ediPricat = new EDI_PRICAT($pricat);
      dd($ediPricat->parseContent(true));
    } else if ($scenario == "scenario_2"){
      // Envoi réel vers TX2
      $ediPricat = new EDI_PRICAT($pricat);
      $ediPricat->sendToTx2(true);
      echo "Sent to Tx2";
    } else if ($scenario == "scenario_3"){
      // Récupérer le chemin du fichier généré
      $ediPricat = new EDI_PRICAT($pricat);
      return response()->download($ediPricat->getFilePath())->deleteFileAfterSend(true);
    } else {
      // Inspecter le PricatContext et ses items
      dd($pricat);
    }
  }

  public function buildLink($filePath){
    return "<a href='" . route('debug.downloadFile') . "?filePath=$filePath'>Télécharger le fichier</a>";
  }

  public function downloadFile(){
    $filePath = Input::get("filePath");
    return response()->download($filePath)->deleteFileAfterSend(true);
  }

  public function sendInvoicesMails(){
    $invoices = Invoice::whereRaw(
      "(DATE(created_at) = DATE(NOW() - INTERVAL 1 DAY) 
        OR (DATE(created_at) = DATE(NOW() - INTERVAL 2 DAY) AND TIME(created_at) > '17:00:00'))"
    )
    ->where("type", "!=", InvoiceType::PROFORMA)
    ->where('reference', 'not like', 'GIFT%')
    ->get();        
    // $result = EmailController::sendInvoicesList($invoices);
    // DebugLog::logJson("sendInvoicesList", $result);

  }

  public function ticket_1027_collections_and_consumers_modification(){
    $scenario = Input::get("scenario");
    $tracker = ProgressTracker::getTracker();

    // collections
    $collectionsToModifyDetails = [
      145 => 144,
      147 => 146
    ];
    $clientCollectionsToModify = ClientCollection::whereIn("product_category_id", array_keys($collectionsToModifyDetails))->limit(1)->get();
    $tracker->setTargetCount(count($clientCollectionsToModify));
    foreach ($clientCollectionsToModify as $clientCollection){
      $tracker->addCount();
      // check if client already has the target collection
      $targetCollectionId = $collectionsToModifyDetails[$clientCollection->product_category_id];
      $targetCollection = ProductCategory::find($targetCollectionId);
      $client = Client::find($clientCollection->client_id);
      if ($client->collections->contains($targetCollectionId)){
        echo '<div style="background-color: #e8f5e9">' . $client->link . " already has the target collection $targetCollection->name<br></div>";
        if ($scenario == "scenario_2"){
          $clientCollection->delete();
          echo 'Modification appliquée<br>';
        }
      } else {
        echo '<div style="background-color: #ffebee">' . $client->link . " does not have the target collection $targetCollection->name<br></div>";
        if ($scenario == "scenario_2"){
          $clientCollection->product_category_id = $targetCollectionId;
          $clientCollection->save();
          echo 'Modification appliquée<br>';
        }
      }
    }

    // consumers
    $consumersToModifyDetails = [
      2 => 1,
    ];
    $clientConsumersToModify = ClientConsumer::whereIn("consumer_category_id", array_keys($consumersToModifyDetails))->limit(50)->get();
    $tracker->setTargetCount(count($clientConsumersToModify));
    foreach ($clientConsumersToModify as $clientConsumer){
      $tracker->addCount();
      // check if client already has the target collection
      $targetConsumerId = $consumersToModifyDetails[$clientConsumer->consumer_category_id];
      $targetConsumer = ConsumerCategory::find($targetConsumerId);
      $client = Client::find($clientConsumer->client_id);
      if ($client->consumers->contains($targetConsumerId)){
        echo '<div style="background-color: #e8f5e9">' . $client->link . " already has the target consumer $targetConsumer->name<br></div>";
        if ($scenario == "scenario_2"){
          $clientConsumer->delete();
          echo "Consumer supprimé $clientConsumer->name<br>";
        }
      } else {
        echo '<div style="background-color: #ffebee">' . $client->link . " does not have the target consumer $targetConsumer->name<br></div>";
        if ($scenario == "scenario_2"){
          $clientConsumer->consumer_category_id = $targetConsumerId;
          $clientConsumer->save();
          echo "Consumer modifié $clientConsumer->name<br>";
        }
      }
    }

  }

  public function upsSignatureTest()
  {
    $response = ParcelTrackingController::getLabelFromUpsApi(Order::find(60802), 11);
    if (isset($response['ShipmentResponse']['ShipmentResults']['PackageResults'][0]['ShippingLabel']['GraphicImage'])) {
        echo '<img src="data:image/gif;base64,' . $response['ShipmentResponse']['ShipmentResults']['PackageResults'][0]['ShippingLabel']['GraphicImage'] . '" style="transform: rotate(90deg); margin-top: 200px; width: 800px;" />';
    } else {
        dd($response);
    }


    // $trackingNumber = TrackingNumber::find(152);
    // dd($trackingNumber->checkTrackingData(true));

    // function getFromUPS($url)
    // {
    //   $headers = array();
    //   $headers[] = 'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept';
    //   $headers[] = 'Access-Control-Allow-Methods: GET';
    //   $headers[] = 'Access-Control-Allow-Origin: *';
    //   $headers[] = 'Content-Type: application/json';
    //   $headers[] = 'transId: ' . uniqid();
    //   $headers = array_merge($headers, ParcelTrackingHelper::get_UPSCredentials_restfulAPI());

    //   $ch = curl_init();
    //   curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    //   curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    //   curl_setopt($ch, CURLOPT_URL, $url);
    //   curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    //   curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    //   $response = curl_exec($ch);

    //   if ((curl_errno($ch)) && (curl_errno($ch) != 0)) {
    //     $response = "::" . curl_errno($ch) . "::" . curl_error($ch);
    //   }

    //   return $response;
    // }


    // $trackingNumbers = [
    //   Input::get("trackingNumber"),
    // ];


    // $tracker = ProgressTracker::getTracker();
    // $tracker->setTargetCount(count($trackingNumbers));
    // $totalCount = count($trackingNumbers);
    // $withSignature = 0;
    // $withoutSignature = 0;

    // foreach ($trackingNumbers as $trackingNumber) {
    //   $tracker->addCount();
    //   $query = array(
    //     "locale" => "fr_FR",
    //     "returnSignature" => "true",
    //     "returnMilestones" => "false",
    //     "returnPOD" => "true"
    //   );

    //   $url = ParcelTrackingHelper::$UPS_TRACKING_URL . $trackingNumber . "?" . http_build_query($query);
    //   $json_string = getFromUPS($url);
    //   $response = json_decode($json_string, true);

    //   // echo $trackingNumber . "<br><br>";
    //   if (isset($response['trackResponse']['shipment'][0]['package'][0]['deliveryInformation']['signature']['image'])) {
    //     $signatureImage = $response['trackResponse']['shipment'][0]['package'][0]['deliveryInformation']['signature']['image'];
    //     echo $signatureImage;
    //     $withSignature++;
    //   } else {
    //     echo "Signature image not found in the response.";
    //     $withoutSignature++;
    //   }
    //   // echo "<br><br>POD Content:<br>";
    //   if (isset($response['trackResponse']['shipment'][0]['package'][0]['deliveryInformation']['pod']['content'])) {
    //     $podContent = $response['trackResponse']['shipment'][0]['package'][0]['deliveryInformation']['pod']['content'];
    //     $decodedPodContent = base64_decode($podContent);
    //     echo $podContent;
    //   } else {
    //     echo "POD content not found in the response.";
    //   }
    //   // Check if receivedBy information is available
    //   if (isset($response['trackResponse']['shipment'][0]['package'][0]['deliveryInformation']['receivedBy'])) {
    //     $receivedBy = $response['trackResponse']['shipment'][0]['package'][0]['deliveryInformation']['receivedBy'];
    //     echo "Received By: " . $receivedBy . "<br>";
    //   } else {
    //     echo "Received By information not found in the response.<br>";
    //   }
    //   dd($response);
    //   // echo "*******************************************************<br><br>";
    // }

    // echo "Total tracking numbers: " . $totalCount . "<br>";
    // echo "Tracking numbers with signature: " . $withSignature . "<br>";
    // echo "Tracking numbers without signature: " . $withoutSignature . "<br>";
  }

  public function testReportingMail(){
    // OrderController::checkOrdersWithoutReference();
    $orders = Order::whereNull("reference")
    ->orWhere("reference", "")
    ->get();
    dd($orders);

  }

  public function updateOrderTotalPrice(){
    set_time_limit(240);
    $orders = Order::whereNull("total_price")->limit(2000)->orderBy("id", "desc")->get();
    $tracker = ProgressTracker::getTracker();
    $tracker->setTargetCount(count($orders));
    $tracker->setSpecificData([ "debugMessage" => "Mise à jour des total_price de " . count($orders) . " commandes" ]);
    foreach ($orders as $order){
      $order->total_price = $order->order_price;
      $order->save();
      $tracker->addCount();
    }
    $tracker->setSpecificData([ "debugMessage" => "Montant total de " . count($orders) . " commandes mis à jour." ]);
  }

  public function matchSecurePaymentsWithVads(){
    $debugLogs = DebugLog::where("log_id", "like", "%SECURE PAYMENT RETURN START%")->get();
    $tracker = ProgressTracker::getTracker();
    $tracker->setTargetCount(count($debugLogs));
    
    foreach ($debugLogs as $debugLog){
      $debugLog->log_content = json_decode($debugLog->log_content, true);
      $securePayment = SecurePayment::find($debugLog->log_content["vads_trans_id"]);
      // conversion d'une date de type 20231102095513 en 2023-11-02 09:55:13
      $date = explode(" ", $debugLog->log_content["vads_presentation_date"])[0];
      $date = date("Y-m-d H:i:s", strtotime($debugLog->log_content["vads_presentation_date"]));

      $details = [
        '$securePayment->id' => $securePayment->id,
        'vads_trans_id' => $debugLog->log_content["vads_trans_id"],
        "vads_auth_number" => $debugLog->log_content["vads_auth_number"],
        "vads_trans_status" => $debugLog->log_content["vads_trans_status"],  
        "vads_presentation_date" => $date,
      ];
      echo '<pre>'; print_r($details); echo '</pre>';
      $securePayment->paid_at = $date;
      $securePayment->save();
      $tracker->addCount();
    }
  }

  public function finbalTests(){
    $scenario = Input::get("scenario");
    $client = Client::find(1);
    if ($scenario == "scenario_1"){
      $invoice = Invoice::find(49161);
      dd("partially paid : " . $invoice->is_partially_paid);
      // $client->unassignInvoicesFromCicFactor();
      // EmailController::sendFactorRibInformationMail(Client::find(1));
      // (new FinbalHelper)->createAllExports();
    } else if ($scenario == "scenario_2"){
      EmailController::sendFactorRibInformationMail(Client::find(1));
      // $client->assignInvoicesToCicFactor();
      // $fileTransfer = FinbalFileTransfer::getFileTransferOfQuantum(FinbalExport::TYPE_FBA);
      // dd([
      //   $fileTransfer->totals,
      //   $fileTransfer->file_totals,
      //   $fileTransfer->syncElementsAmountsComparison,
      //   $fileTransfer->content
      // ]);
    } else if ($scenario == "scenario_3"){
      // $data = (new FinbalHelper)->addClientsNoLongerInCicFactor([
      //   "clients" => collect([]),
      //   "invoices" => collect([]),
      // ]);
      // dd($data);
      // $fileTransfer = FinbalFileTransfer::getFileTransferOfQuantum(FinbalExport::TYPE_FBA);
      // $fileTransfer->checkErrors();
    }

    // (new FinbalHelper)->createAllExports();

    // $data = (new FinbalHelper)->parseBalanceFileOfQuantum(255);
    // dd($data);
    // $element = FinbalSyncElement::find(8206);
    // dd($element->errorsFormatted);
    // dd((new FinbalHelper)->parseFileContent(file_get_contents(storage_path("temp/exports/TIEFC0042511A.239"))));
  }

  public function debugAccountingOverview()
  {
    AppGlobalSetting::updateStoredStats();
    // AppGlobalSetting::storeStats(AppGlobalSetting::STATS_CREDITS, [ "test" => "this is a test"]);
    // dd(AppGlobalSetting::getStats(AppGlobalSetting::STATS_CREDITS));
    // $overdueCashInvoices = InvoiceRepository::getOverdueCashInvoices();
    // dd($overdueCashInvoices);
    // $creances = AppGlobalSetting::getStats(AppGlobalSetting::STATS_CREANCES_BY_QUARTER);
    // $creances[2019][3] = "test";
    // AppGlobalSetting::storeStats(AppGlobalSetting::STATS_CREANCES_BY_QUARTER, $creances);
    // dd($creances);
    // $credits = AccountingOverviewController::getCredits();
    // dd($credits);
  }

  public function TX2_tests()
  {
    // $file = Input::file("debugFile");
    // $content = file_get_contents($file->getRealPath());
    // // $result = EdiHelper::parsePositionnedFileContent($content);
    // $result = EdiHelper::parseDelimitedFileContent(EdiProperty::POSITIONNED_FILE_TYPE_SLSRP, $content);
    // dd($result);

    // $tx2 = TX2_Sftp::getSfpt(true);
    // TX2_Sftp::downloadInvoicFiles(true);
    // TX2_Sftp::downloadFiles(EdiProperty::FILE_TYPE_SLSRPT, true);
    // TX2_Sftp::downloadFiles(EdiProperty::FILE_TYPE_SLSRPT, true);
    // $files = EdiHelper::getImportedFiles(EdiProperty::FILE_TYPE_SLSRPT);
    // $SLSRPT = [];
    // foreach ($files as $file) {
    //   $content = file_get_contents($file["path"]);
    //   $parsed_SLSRPT = EdiHelper::parseDelimitedFileContent(EdiProperty::POSITIONNED_FILE_TYPE_SLSRP, $content);
    //   $sales = EdiHelper::createOrderFromSLSRPT($parsed_SLSRPT);
    //   $SLSRPT[] = [
    //     "file" => $file,
    //     "sales" => $sales
    //   ];
    //   foreach ($sales["orders"] as $order){
    //     $order = Order::find($order["id"]);
    //     InvoiceHelper::createInvoice($order);
    //   }
    // }

    // dd($SLSRPT);

    // $tx2 = new SftpConnection(
    //   config('TX2.sftp.host'), 
    //   config('TX2.sftp.port'), 
    //   config('TX2.sftp.username'), 
    //   config('TX2.sftp.password')
    // );
    // $tx2->uploadFile(storage_path("INVOIC-UPLOAD-TEST.txt"), "test/invoic/test-invoic-upload.txt");
    // $tx2->close(true);
  }


  public function operationIntOrdersTests()
  {
    $intOrder = OperationsIntOrder::find(20422);
    // $intItem = $intOrder->items[5];
    // $intItem->product_unit_price = 18;
    // $intItem->save();
    // dd($intItem);
    dd($intOrder->extOrder->synchroOperations());

    // $items = $intOrder->items->map(function ($intItem) {
    //   return [
    //     "product" => [
    //       "reference" => $intItem->product->completeReference,
    //       "size" => $intItem->packItem->size_label,
    //     ],
    //     "orderItem" => $intItem->orderItem ? [
    //       "product_reference" => $intItem->orderItem->product_reference,
    //       "order_reference" => $intItem->orderItem->order->reference,
    //     ] : "no order item !"
    //   ];
    // });

    // $allItemsFound = true;
    // foreach ($items as $item) {
    //   if ($item["orderItem"] == "no order item !") {
    //     echo "************************** NO ORDER ITEM<br>";
    //     echo '<pre>'; print_r($item); echo '</pre>';
    //     $allItemsFound = false;
    //   }
    // }
    // if ($allItemsFound) {
    //   echo "************************** ALL ITEMS FOUND<br>";
    // }

    // echo '<pre>'; print_r($items); echo '</pre>';
  }

  public function createDefaultBrandUserAssociation($brandId)
  {
    $users = User::all();
    foreach ($users as $user) {
      UserBrandAssociation::create([
        "user_id" => $user->id,
        "brand_id" => $brandId
      ]);
    }
  }

  public function multipleAdresses()
  {
    $results = Client::select([
      'clients.reference',
      'clients.id',
      DB::raw('count(*) as addressesCount'),
      DB::raw('GROUP_CONCAT(addresses.id) as ids'),
    ])
      ->join('addresses', function ($join) {
        $join->on('addresses.addressable_id', '=', 'clients.id')
          ->where('addresses.addressable_type', '=', 'App\\Models\\Client');
      })
      ->where('addresses.is_billing', 0)
      ->whereNull('addresses.deleted_at')
      ->groupBy('clients.id')
      ->havingRaw('count(*) > 1')
      ->get();

    // COuting addresses
    foreach ($results as $result) {
      $ids = explode(",", $result->ids);
      $differentAddresses = [];
      $clientAddresses = Address::whereIn('id', $ids)->get();
      foreach ($clientAddresses as $currentAddress) {
        if (count($differentAddresses) == 0) {
          $differentAddresses[] = $currentAddress;
        } else {
          $addressAlreadyInList = false;
          foreach ($differentAddresses as $addressToSearch) {
            if (
              $currentAddress->street === $addressToSearch->street
              && $currentAddress->city === $addressToSearch->city
              && $currentAddress->state === $addressToSearch->state
              && $currentAddress->post_code === $addressToSearch->post_code
              && $currentAddress->note === $addressToSearch->note
            ) {
              $addressAlreadyInList = true;
            }
          }
          if (!$addressAlreadyInList) {
            $differentAddresses[] = $currentAddress;
          }
        }
      }
      if (count($differentAddresses) != count($clientAddresses)) {
        echo "<br><br>********************************************************************************************************************<br>";
        echo $result->link . " : " . count($clientAddresses) . " adresses / " . count($differentAddresses) . "adresses différentes<br/><br/>";
        foreach ($differentAddresses as $currentAddress) {
          echo "<br>*****************************<br>";
          echo $currentAddress->street . "<br>";
          echo $currentAddress->city . "<br>";
          echo $currentAddress->state . "<br>";
          echo $currentAddress->post_code . "<br>";
          echo $currentAddress->note . "<br>";
          echo "*****************************<br><br><br>";
        }
      }
    }
  }

  public function searchForClientCreationWithNoAgentTicket_568()
  {

    // Name analysis

    // $clientsWithNoAgent = Client::whereNull("agent_id")->get();
    // // for each $firstAgentHistory, search for another HistoriqueRepresentant with same client name^
    // $anomalies = [];
    // foreach ($clientsWithNoAgent as $client){
    //   $similarHistory = HistoriqueRepresentant::query()
    //     ->select(["historique_representants.*", "clients.name"])
    //     ->whereNull("old_agent_id")
    //     ->whereNotNull("new_agent_id")
    //     ->join("clients", "clients.id", "=", "historique_representants.client_id")
    //     ->where("clients.name", "like", substr($client->name, 0, 14) . "%")
    //     ->where("clients.id", "!=", $client->id)
    //     ->orderBy("historique_representants.id", "asc")
    //     // ->where(DB::raw("historique_representants.id - " . $history->id), "<", 100)
    //     ->first();
    //   if ($similarHistory) {
    //     // echo "<h2>" . $history->client->name . "<br></h2>";
    //     // echo $history->client->link . " - " . $history->id . "<br>";
    //     // foreach ($similarHistory as $history2) {
    //       if ($similarHistory->old_agent_id === null){
    //         // Add $history->client->link, $history->client->name, $history2->client->link, $history2->client->name to anomalies
    //         $anomalies[] = [
    //           "client" => $client->link,
    //           "client_name" => $client->name,
    //           "similarHistoryLink" => $similarHistory->client->link,
    //           "similarHistoryName" => $similarHistory->client->name,
    //         ];
    //       }
    //       // echo $history2->client->link  . " - " . $history2->id . "<br>";
    //     // }
    //   }
    // }
    // echo '<pre>$anomalies<br />'; var_dump($anomalies); echo '</pre>';

    // id analysis
    DB::statement('SET SESSION group_concat_max_len = 1000000');
    $histories = HistoriqueRepresentant::query()
      ->whereNull("old_agent_id")
      ->whereNotNull("new_agent_id")
      ->selectRaw("GROUP_CONCAT(client_id ORDER BY client_id) AS client_ids, change_by")
      ->groupBy("change_by")
      ->get();
    $anomalies = [];
    foreach ($histories as $history) {
      $ids = explode(",", $history->client_ids);
      $maxDistance = 10;
      for ($i = 1; $i < count($ids); $i++) {
        $referenceId = intval($ids[$i]);
        for ($distance = 1; $distance <= $maxDistance; $distance++) {
          $otherId = array_search($referenceId + $distance, $ids);
          if ($otherId !== false) {
            $client1 = Client::find($referenceId);
            $client2 = Client::find($referenceId + $distance);
            if (
              (substr($client1->name, 0, 10) === substr($client2->name, 0, 10))
              && ($client1->agent_id === null)
            ) {
              // if ($client1->name === $client2->name){
              $anomalies[] = [
                "client" => $client1->link,
                "client_name" => $client1->name,
                "client2" => $client2->link,
                "client2_name" => $client2->name,
              ];
            }
          }
        }
      }
    }
    echo '<pre>$anomalies<br />';
    var_dump($anomalies);
    echo '</pre>';
  }


  public function checksWithMultipleInvoices()
  {
    // Check::join(CheckInvoice::$table, CheckInvoice::$table . ".check_id", "=", Check::$table . ".id")
    $check = Check::find(2635);
    $check = Check::find(5098);
    echo '<pre>$check->amount_cents / 100;<br />';
    var_dump($check->amount_cents / 100);
    echo '</pre>';
    dd($check->invoices->reduce(function ($total, $invoice) {
      return $total + $invoice->total_calculations["raw_total_ttc"];
    }));
  }

  public function ciblexTests()
  {
    function sendInfosGET($url)
    {
      $ciblexCredentials = [
        'exp_code' => config('api_keys.ciblex_credentials.exp_code'),
        'contrat' => config('api_keys.ciblex_credentials.contrat'),
        'i' => 208,
        'k' => config('api_keys.ciblex_credentials.k'),
      ];

      $order = Order::find(49848);

      $labelParams = array_merge(
        [
          "label_type" => "STD",
        ],
        ParcelTrackingController::shipperInfos(CarriersList::CIBLEX),
        ParcelTrackingController::getShipToFromOrder($order, CarriersList::CIBLEX)
      );

      $urlParms = http_build_query(array_merge($ciblexCredentials, $labelParams));
      $url = $url . "?" . $urlParms;
      $headers = array();
      $headers[] = 'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept';
      $headers[] = 'Access-Control-Allow-Origin: *';
      $headers[] = 'Content-Type: application/json';
      // $headers[] = 'transId: ' . $transId;

      $ch = curl_init();

      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
      curl_setopt($ch, CURLOPT_TIMEOUT, 45);
      curl_setopt($ch, CURLOPT_URL, $url);
      // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_HEADER, false);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);


      $response = curl_exec($ch);

      return $response;
    }
    ConvertApi::setApiSecret(config('api_keys.convert_api_key'));
    $filename = storage_path("temp/test.pdf");
    $filenamePNG = storage_path("temp/test.png");
    $pdf = sendInfosGET("https://secure.extranet.ciblex.fr/extranet/test/label.php");
    file_put_contents($filename, $pdf);
    $result = ConvertApi::convert(
      'png',
      [
        'File' => $filename,
      ],
      'pdf'
    );
    $result->saveFiles($filenamePNG);
    $image = imagecreatefrompng($filenamePNG);
    $image = imagecropauto($image, IMG_CROP_WHITE);
    imagepng($image, $filenamePNG);
    return Response::download($filenamePNG)->deleteFileAfterSend(true);
  }

  public function ciblexShippingTrackingTest()
  {
    function sendInfosPOST($url)
    {
      $ciblexCredentials = [
        'exp_code' => config('api_keys.ciblex_credentials.exp_code'),
        'contrat' => config('api_keys.ciblex_credentials.contrat'),
        'i' => 208,
        'k' => config('api_keys.ciblex_credentials.k'),
      ];


      $trackingNumber = [
        "cb" => "05050000326509",
        // "a" => "tracking",
        "a" => "pod",
        // "a" => "searchByRef",
        // "ref" => "OD-043104",
        "date" => "02022023",
        "time" => "105700"
      ];

      $postFields = (array_merge($ciblexCredentials, $trackingNumber));

      // $headers = array();
      // $headers[] = 'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept';
      // $headers[] = 'Access-Control-Allow-Origin: *';
      // $headers[] = 'Content-Type: application/json';
      // $headers[] = 'transId: ' . $transId;

      $ch = curl_init();

      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);


      $response = curl_exec($ch);

      return $response;
      // if ((curl_errno($ch)) && (curl_errno($ch) != 0)) {
      //     $response = "::" . curl_errno($ch) . "::" . curl_error($ch);
      //     return $response;
      // }

      // $response = json_decode($response, true);
      // $response["UPSTransId"] = $transId;
      // return $response;
    }
    return sendInfosPOST('http://api.ciblex.fr/api.php');
  }

  public function restockingAnalysis()
  {
    $bornes = [
      [0],
      [0, 10],
      [10, 20],
      [20, 30],
      [30, 40],
      [40, 50],
      [50, 100],
      [0, 10],
      [200, 500],
      [500, 1000],
      [1000, 1500],
      [1500]
    ];

    $productQuantityChanges = [];
    foreach ($bornes as $borne) {
      if (isset($borne[1])) {
        $productQuantityChanges[] = ProductQuantityChange::selectRaw("count(*) as totalMvt")
          ->whereRaw("(quantity_after - quantity_before) >= ?", [$borne[0]])
          ->whereRaw("(quantity_after - quantity_before) < ?", [$borne[1]])
          ->get()->first();
      } else {
        $productQuantityChanges[] = ProductQuantityChange::selectRaw("count(*) as totalMvt")
          ->whereRaw("quantity_after - quantity_before >= ?", [$borne[0]])
          ->get()->first();
      }
    }

    foreach ($productQuantityChanges as $index => $change) {
      if (isset($bornes[$index][1])) {
        echo ">= " . $bornes[$index][0] . " & < " . $bornes[$index][1] . ' : ' . $change["totalMvt"] . "<br>";
      } else {
        echo ">= " . $bornes[$index][0] . " & < : " . $change["totalMvt"] . "<br>";
      }
    }
  }

  public function preorderCheck()
  {
    $item = PreorderItem::find(369000);
    echo '<pre>$item->product_reference<br />';
    var_dump($item->product_reference);
    echo '</pre>';
    echo '<pre>$item->total_quantity<br />';
    var_dump($item->total_quantity);
    echo '</pre>';
    echo '<pre>$item->quantity_ordered<br />';
    var_dump($item->quantity_ordered);
    echo '</pre>';
    echo '<pre>$item->orders_total_quantity<br />';
    var_dump($item->orders_total_quantity);
    echo '</pre>';
    echo '<pre>$item->remaining_total_quantity<br />';
    var_dump($item->remaining_total_quantity);
    echo '</pre>';
    echo '<pre>$item->getOrdersOrderedOrPreparedQuantityAttribute()<br />';
    var_dump($item->getOrdersOrderedOrPreparedQuantityAttribute());
    echo '</pre>';
    echo '<pre>$item->has_non_invoiced_orders<br />';
    var_dump($item->has_non_invoiced_orders);
    echo '</pre>';
    // dd($item->is_invoiced);
    dd($item->orders);
  }

  public function debugInvoiceTvaError()
  {
    function getTotalCalculations($invoice, $correctTva)
    {
      $total_ht = $invoice->items_price;

      $remise = $invoice->discount;
      $avoir = $invoice->avoir_amount;
      $fdt = $invoice->delivery_fees;
      $fdt_ht = $invoice->delivery_fees;

      $total_ht_after_discount = $total_ht - $remise - $avoir;

      // VAT calculation
      $tva = 0;
      if ($invoice->has_tva) {
        $fdt_ht /= 1.2;
        $discountPercentage = $invoice->discount_percentage;
        foreach ($invoice->items as $item) {
          $priceDiscounted = $item->total_paid_ht * (1 - $discountPercentage);
          if ($item->tva == 0 && $correctTva) $item->tva = 20;
          $tva += $priceDiscounted * ($item->tva / 100);
        }
      }
      $tva_totale = $tva + ($fdt - $fdt_ht);
      $total_ttc = $total_ht_after_discount + $tva;
      $total_final = $total_ht_after_discount + $fdt_ht + $tva_totale;

      if ($invoice->order && $invoice->order->financing_plan_count) {
        $total_ht /= $invoice->order->financing_plan_count;
        $total_ht_after_discount /= $invoice->order->financing_plan_count;
        $fdt_ht /= $invoice->order->financing_plan_count;
        $fdt /= $invoice->order->financing_plan_count;
        $remise /= $invoice->order->financing_plan_count;
        $avoir /= $invoice->order->financing_plan_count;
        $tva /= $invoice->order->financing_plan_count;
        $total_ttc /= $invoice->order->financing_plan_count;
        $total_final /= $invoice->order->financing_plan_count;
      }

      return [
        'total_ht' => number_format(round($total_ht, 2), 2, ',', ' '),
        'total_ht_after_discount' => number_format(round($total_ht_after_discount, 2), 2, ',', ' '),
        'total_ht_after_discount_plus_delivery_fees' => number_format(round($total_ht_after_discount + $fdt_ht, 2), 2, ',', ' '),
        'remise' => number_format(round($remise, 2), 2, ',', ' '),
        'avoir' => number_format(round($avoir, 2), 2, ',', ' '),
        'tva' => number_format(round($tva, 2), 2, ',', ' '),
        'tva_totale' => number_format(round($tva_totale, 2), 2, ',', ' '),
        'delivery_fees_ht' => number_format(round($fdt_ht, 2), 2, ',', ' '),
        'delivery_fees' => number_format(round($fdt, 2), 2, ',', ' '),
        'total_ttc' => number_format(round($total_ttc, 2), 2, ',', ' '),
        'total_final' => number_format(round($total_final, 2), 2, ',', ' '),

        'raw_total_ht' => $total_ht,
        'raw_total_ht_after_discount' => $total_ht_after_discount,
        'raw_total_ht_after_discount_plus_delivery_fees' => $total_ht_after_discount + $fdt_ht,
        'raw_remise' => $remise,
        'raw_avoir' => $avoir,
        'raw_tva' => $tva,
        'raw_tva_totale' => $tva_totale,
        'raw_delivery_fees' => $fdt,
        'raw_delivery_fees_ht' => $fdt_ht,
        'raw_total_ttc' => $total_ttc,
        'raw_total_final' => $total_final,
      ];
    }

    $tests = [
      "FA-0029936",
      "PROF-OD-037146",
      "FA-0029965",
      "FA-0030015",
      "FA-0030059",
      "FA-0030060",
      "FA-0030068",
      "FA-0030093",
      "FA-0030094",
      "FA-0030109",
      "FA-0030158",
    ];

    $invoices = Invoice::whereIn("reference", $tests);
    $invoices->each(function ($invoice) {
      echo '<pre>$invoice->reference<br />';
      var_dump($invoice->reference);
      echo '</pre>';
      // getTotalCalculations($invoice)["raw_tva"];
      echo '<pre>getTotalCalculations($invoice)[\"raw_tva\"]<br />';
      var_dump(getTotalCalculations($invoice, false)["raw_tva"]);
      echo '</pre>';
      echo '<pre>getTotalCalculations($invoice)[\"raw_tva\"]<br />';
      var_dump(getTotalCalculations($invoice, true)["raw_tva"]);
      echo '</pre>';
      echo "<br>";
      echo "************************************************************************************************";
      echo "<br>";
    });
  }

  public function productsPromoSegmentations()
  {
    $productsWithPromo = Product::where("promo_b2b", "!=", 0)
      ->join("products_segmentations", "products_segmentations.product_id", "=", "products.id")
      ->get();
    // echo date_create()->format('Y-m-d H:i:s');
    foreach ($productsWithPromo as $product) {
      $product->updated_at = date_create()->format('Y-m-d H:i:s');
      $product->save();
      // $addResult = $product->addSegmentation("Multi-marque");
      // if ($addResult !== true){
      //   echo "Erreur product id : $product->id - $product->reference-$product->color_reference";
      //   if ($addResult === false){
      //     echo '<pre>$addResult<br />'; var_dump($addResult); echo '</pre>';
      //   } else {
      //     echo '<pre>$addResult<br />'; var_dump($addResult->getMessage()); echo '</pre>';
      //   }
      // }
      // $addResult = $product->addSegmentation("Intersport");
      // if ($addResult !== true){
      //   echo "Erreur product_id : $product->id - $product->reference-$product->color_reference";
      //   if ($addResult === false){
      //     echo '<pre>$addResult<br />'; var_dump($addResult); echo '</pre>';
      //   } else {
      //     echo '<pre>$addResult<br />'; var_dump($addResult->getMessage()); echo '</pre>';
      //   }
      // }
      // $addResult = $product->addSegmentation("Sport 2000");
      // if ($addResult !== true){
      //   echo "Erreur product_id : $product->id - $product->reference-$product->color_reference";
      //   if ($addResult === false){
      //     echo '<pre>$addResult<br />'; var_dump($addResult); echo '</pre>';
      //   } else {
      //     echo '<pre>$addResult<br />'; var_dump($addResult->getMessage()); echo '</pre>';
      //   }
      // }
    }
  }

  public function checkStockAtDate()
  {
    $test = DB::select("SELECT *
    FROM product_quantity_changes PQC
    WHERE product_id = 5431
    AND created_at = (
      SELECT MAX(PQC2.created_at) 
      FROM product_quantity_changes PQC2
      WHERE PQC2.product_id = 5431
      AND PQC2.created_at <= '2022-08-24'
    )");
    dd($test);
  }

  public function updateLastPackItemQuantityChange()
  {
    DB::transaction(function () {
      $items = PackItem::where("id", ">", 125847)->get();
      foreach ($items as $packItem) {
        $lastChange = ProductQuantityChange::where("pack_item_id", $packItem->id)->orderBy("created_at", "desc")->first();
        if (!$lastChange) {
          $quantityBefore = 0;
          ProductQuantityChange::create([
            "pack_item_id" => $packItem->id,
            "quantity_before" => $quantityBefore,
            "quantity_after" => $packItem->pieces_available,
            "user_id" => 28,
            'type' => ProductQuantityChangeType::MANUAL,
            'warehouse_id' => Warehouse::mainWarehouseId
          ]);
        } else {
          if ($lastChange->quantity_after === $packItem->pieces_available) {
            continue;
          }
          $quantityBefore = $lastChange->quantity_after;
          ProductQuantityChange::create([
            "pack_item_id" => $packItem->id,
            "quantity_before" => $quantityBefore,
            "quantity_after" => $packItem->pieces_available,
            "user_id" => 28,
            'type' => ProductQuantityChangeType::MANUAL,
            'warehouse_id' => Warehouse::mainWarehouseId
          ]);
        }
      }
    });
  }

  public function invoiceTotalCalculationsDebug($invoice)
  {

    $total_ht = $invoice->items_price;

    $remise = $invoice->discount;
    $avoir = $invoice->avoir_amount;
    $allCredits = $invoice->credit_amount;
    $fdt = $invoice->delivery_fees;
    $fdt_ht = $invoice->delivery_fees;

    $total_ht_after_discount = $total_ht - $remise;
    $total_including_credits_ht_after_discount = $total_ht - $remise - $allCredits;

    // VAT calculation
    $tva = 0;
    if ($invoice->has_tva) {
      $fdt_ht /= 1.2;
      $discountPercentage = $invoice->discount_percentage;
      foreach ($invoice->items as $item) {
        $priceDiscounted = $item->total_paid_ht * (1 - $discountPercentage);
        $tva += $priceDiscounted * ($item->tva / 100);
      }
    }
    $tva_totale = $tva + ($fdt - $fdt_ht);
    $total_ttc = $total_ht_after_discount + $tva;
    $total_final = $total_ht_after_discount + $fdt_ht + $tva_totale;

    if ($invoice->order && $invoice->order->financing_plan_count) {
      $total_ht /= $invoice->order->financing_plan_count;
      $total_ht_after_discount /= $invoice->order->financing_plan_count;
      $fdt_ht /= $invoice->order->financing_plan_count;
      $fdt /= $invoice->order->financing_plan_count;
      $remise /= $invoice->order->financing_plan_count;
      $avoir /= $invoice->order->financing_plan_count;
      $tva /= $invoice->order->financing_plan_count;
      $total_ttc /= $invoice->order->financing_plan_count;
      $total_final /= $invoice->order->financing_plan_count;
    }
    $stock = WarehouseStock::find(3466);
    dd($stock->changes);

    // $products = Product::all();
    // foreach ($products as $product) {
    //   $product->addSegmentation("Espagne multi-marque");
    // }

    // $this->updateLastPackItemQuantityChange();
    // $preorder = Preorder::find(4700);
    // $items = $preorder->items->map(function ($item) {
    //   return [
    //     "reference" => $item->product_reference,
    //     "allocable" => $item->product->allocable_quantity + $item->total_quantity,
    //     "preordered" => $item->total_quantity,
    //     "total_preordered" => $item->product->preordered_quantity,
    //     "preordered_quantity_still_to_order" => $item->product->preordered_quantity_still_to_order,
    //     "available" => $item->product->quantity_available,
    //     "remains_to_deliver" => $item->product->remains_to_deliver,
    //     "arrivals" => implode($item->product->restockings->filter(function ($item) {
    //       return $item->date >= Carbon::now();
    //     })->map(function ($restocking) {
    //       return $restocking->arrival->reference;
    //     })->toArray())
    //   ];
    // })->toArray();
    // uasort($items, function ($a, $b) {
    //   return $a["allocable"] - $b["allocable"];
    // });
    // $items = array_filter($items, function ($item) {
    //   return $item["allocable"] <= 0;
    // });
    // foreach ($items as $item) {
    //   // echo "
    //   // <div style='margin-bottom: 10px;'>
    //   //   <div style='display: inline-block; width: 100px;'>" . $item["reference"] . "</div>" .
    //   //   "<div style='margin-left: 20px;'>" .
    //   //   "<div>Allouable : " . $item["allocable"] . "</div>" .
    //   //   "<div>Préco : " . $item["preordered"] . "</div>" .
    //   //   "<div>available : " . $item["available"] . "</div>" .
    //   //   "<div>total_preordered : " . $item["total_preordered"] . "</div>" .
    //   //   "<div>preordered_quantity_still_to_order : " . $item["preordered_quantity_still_to_order"] . "</div>" .
    //   //   "<div>remains_to_deliver : " . $item["remains_to_deliver"] . "</div>" .
    //   //   "<div>arrivals : " . $item["arrivals"] . "</div>" .
    //   //   "</div>
    //   // </div><hr>";
    // }

    return [
      'total_ht' => number_format(round($total_ht, 2), 2, ',', ' '),
      'total_ht_after_discount' => number_format(round($total_ht_after_discount, 2), 2, ',', ' '),
      'total_ht_after_discount_plus_delivery_fees' => number_format(round($total_ht_after_discount + $fdt_ht, 2), 2, ',', ' '),
      'remise' => number_format(round($remise, 2), 2, ',', ' '),
      'avoir' => number_format(round($avoir, 2), 2, ',', ' '),
      'tva' => number_format(round($tva, 2), 2, ',', ' '),
      'tva_totale' => number_format(round($tva_totale, 2), 2, ',', ' '),
      'delivery_fees_ht' => number_format(round($fdt_ht, 2), 2, ',', ' '),
      'delivery_fees' => number_format(round($fdt, 2), 2, ',', ' '),
      'total_ttc' => number_format(round($total_ttc, 2), 2, ',', ' '),
      'total_final' => number_format(round($total_final, 2), 2, ',', ' '),

      'raw_total_ht' => $total_ht,
      'raw_total_ht_after_discount' => $total_ht_after_discount,
      'raw_total_ht_after_discount_plus_delivery_fees' => $total_ht_after_discount + $fdt_ht,
      'raw_total_including_credits_ht_after_discount_plus_delivery_fees' => $total_including_credits_ht_after_discount + $fdt_ht,
      'raw_remise' => $remise,
      'raw_avoir' => $avoir,
      'raw_tva' => $tva,
      'raw_tva_totale' => $tva_totale,
      'raw_delivery_fees' => $fdt,
      'raw_delivery_fees_ht' => $fdt_ht,
      'raw_total_ttc' => $total_ttc,
      'raw_total_final' => $total_final,
    ];
  }

  public function convertXmlToJson($xml_string)
  {
    function flatten($array)
    {
      if (is_string($array)) return $array;
      if (isset($array["@attributes"])) return $array["@attributes"];
      $output = [];
      foreach ($array as $key => $value) {
        $output[$key] = (is_array($value)) ? flatten($value) : $value;
      }
      return $output;
    }
    $xmlDecoded = json_decode(json_encode((new SimpleXMLElement($xml_string))->xmlData), true);
    return json_encode(flatten($xmlDecoded), JSON_PRETTY_PRINT);
  }


  public function general()
  {
    // $test = [];
    // if ($test["hello"] == 12) echo "WTF ?";
    $test = [];
    if ($test["hello"] == 12) echo "WTF ?";
    // $product = Product::find(5);
    // $piece = $product->items[0];

    // dd($product->items->map(function ($packItem) {
    //   return array_map(function ($stock){ 
    //     return [
    //       "id" => $stock->id
    //     ];
    //   }, $packItem->all_warehouses_stocks);
    // }));
    // $product = Product::find(2636);
    // $warehouseStock = $product->getWarehouseStock(2);
    // $warehouseStock->decreaseQuantity(50, ProductQuantityChangeType::ARRIVAL, 487);

    // $warehouse = Warehouses::get()->first();
    // $transfer = $warehouse->transfersFrom->first();
    // dd($transfer->transferItems->map(function ($transferItem) { return $transferItem->product; }));
    $product = Product::find(5);
    echo '<pre>$product->total_stock<br />';
    var_dump($product->total_stock);
    echo '</pre>';
    dd(array_map(function ($warehouseStock) {
      return ["ref" => $warehouseStock->warehouse->reference, "quantity" => $warehouseStock->quantity];
    }, $product->warehouse_stocks));
    $product = Product::find(2636);
    $warehouseStock = $product->getWarehouseStock(2);
    $warehouseStock->decreaseQuantity(50, ProductQuantityChangeType::ARRIVAL, 487);
  }

  public function testPiecesLoctationsInsert()
  {
    $creationResults = PieceLocation::createNewLocations([
      'aisle' => "U",
      'rows' => 1,
      'shelves' => 4,
      'slots' => 3,
    ]);
    $creationResults = array_map(function ($result) {
      if ($result["completeError"]->getCode() == "23000") {
        $result["customMessage"] = "Un emplacement déjà existant a été trouvé : " .
          $result["location"]["aisle"] .
          str_pad(strVal($result["location"]["row"]), 2, "0", STR_PAD_LEFT) . "$" .
          str_pad(strVal($result["location"]["rack"]), 2, "0", STR_PAD_LEFT) .
          $result["location"]["shelf"] .
          $result["location"]["slot"];
      }

      return $result;
    }, $creationResults);
  }

  public function getPieceLocationInfos()
  {
    $locations = PieceLocation::get();
    $formattedLocations = [];
    foreach ($locations as $location) {
      $formattedLocations[$location["aisle"]][$location["row"]][$location["rack"]][$location["shelf"]][$location["slot"]] = 1;
    }
    dd($formattedLocations);
  }

  public function testPiecesInventories()
  {
    $inventory = PieceInventory::find(19);
    dd($inventory->has_active_control);
  }


  public function productChangeAnalysis()
  {
    if (Auth::user()->id != 28) return 403;
    $productChangeList = ProductQuantityChange::orderBy("product_id")
      ->orderBy('created_at')
      ->limit(200000)
      // ->where('product_id', 1540)
      ->get();
    $lastIndex = 0;
    // dd($productChangeList);
    foreach ($productChangeList as $index => $productChange) {

      $lastIndex = $index;
      try {
        if ($index < count($productChangeList) - 1) {
          $nextProductChange = $productChangeList[$index + 1];
          if (
            $nextProductChange->type === "order"
            && $nextProductChange->product_id !== null
            && $nextProductChange->order_id !== null
            && $nextProductChange->product_id == $productChange->product_id
            && $nextProductChange->order_id == $productChange->order_id
            && ($nextProductChange->quantity_after - $nextProductChange->quantity_before) == ($productChange->quantity_after - $productChange->quantity_before)
          ) {
            $firstDateChange = date_create($productChange['created_at']);
            $secondDateChange = date_create($nextProductChange['created_at']);
            $firstDateChange->add(new DateInterval('PT40S'));
            if ($firstDateChange > $secondDateChange) {
              echo "******************************Modification très proche trouvée !<br>&nbsp;&nbsp;- Product id : {$productChange->product_id}<br>&nbsp;&nbsp;- Date : {$productChange->created_at}<br><br><br>";
            }
          }
        }
      } catch (\Throwable $th) {
        echo "$index - {$th->getMessage()} <br>&nbsp;&nbsp;- Product id : {$productChange->product_id}<br>&nbsp;&nbsp;- Date : {$productChange->created_at}<br><br><br>";
      }
    }
    return "End of script - $lastIndex entrées traitées";
  }

  public function testInvoicesCreatedToday()
  {
    $invoices = Invoice::whereRaw('DATE(created_at) = DATE(NOW())')->where("type", "!=", InvoiceType::PROFORMA)->get();
    dd($invoices);
  }

  public function addSegmentation($product, $segmentationName)
  {
    $segmentation = Segmentation::where("name", $segmentationName)->first();
    if (!$segmentation) return "Segmentation inconnue - $segmentationName<br>";
    ProductSegmentation::create([
      "product_id" => $product->id,
      "segmentation" => $segmentation->name
    ]);
    return "Segmentation $segmentationName ajoutée à $product->reference-$product->color_reference<br>";
  }

  public function newSegmentations()
  {
    $productRefList = [

      "1801" => "Black store",
      "1910076" => "Black store",
      "1920010" => "Black store",
      "1950008" => "Intersport",
      "1990019" => "Intersport",
      "2020086" => "Sport 2000",
      "2030064" => "Intersport",
      "2040064" => "Intersport",
      "2040086" => "Sport 2000",
      "2090027" => "Black store",
      "2110187" => "Intersport",
      "2120128" => "Sport 2000",
      "2120131" => "Intersport",
      "2120133" => "Black store",
      "2120135" => "Black store",
      "2120204" => "Intersport",
      "2120207" => "Black store,Sport 2000",
      "2120208" => "Intersport",
      "2120224" => "Black store",
      "2120225" => "Black store",
      "2120232" => "Black store",
      "2130094" => "Black store",
      "2130095" => "Sport 2000",
      "2130100" => "Black store",
      "2130102" => "Sport 2000",
      "2130104" => "Black store",
      "2130136" => "Black store",
      "2130140" => "Black store",
      "2130141" => "Sport 2000",
      "2140131" => "Intersport",
      "2140133" => "Black store",
      "2140136" => "Black store",
      "2140141" => "Sport 2000",
      "2140150" => "Black store",
      "2140204" => "Intersport",
      "2210207" => "Black store",
      "2210224" => "Black store",
      "2210225" => "Black store",
      "2210300" => "Black store",
      "2210302" => "Sport 2000",
      "2210304" => "Sport 2000",
      "2210310" => "Sport 2000",
      "2210313" => "Sport 2000",
      "2210315" => "Sport 2000",
      "2220140" => "Black store",
      "2220143" => "Sport 2000",
      "2220147" => "Sport 2000",
      "2220149" => "Black store",
      "2220151" => "Sport 2000",
      "2220153" => "Black store",
      "2220154" => "Sport 2000",
      "2220157" => "Black store",
      "2220163" => "Intersport",
      "2220165" => "Black store",
      "2220166" => "Black store",
      "2220167" => "Black store",
      "2230106" => "Sport 2000",
      "2230108" => "Black store",
      "2230109" => "Black store",
      "2230113" => "Black store",
      "2230114" => "Sport 2000",
      "2230116" => "Black store",
      "2230118" => "Sport 2000",
      "2230158" => "Intersport",
      "2230164" => "Intersport",
      "2230173" => "Black store",
      "2230180" => "Sport 2000",
      "2240147" => "Sport 2000",
      "2240157" => "Black store",
      "2240158" => "Intersport",
      "2240159" => "Black store",
      "2240163" => "Intersport",
      "2240164" => "Intersport",
      "2240165" => "Black store",
      "2240166" => "Black store",
      "2240167" => "Black store",
      "2240180" => "Sport 2000",
      "2250021" => "Black store",
      "2250022" => "Sport 2000",
      "B2250" => "Black store",
      "CA21017" => "Black store",
      "CA22020" => "Sport 2000",
      "CA22022" => "Black store",
      "CA22023" => "Sport 2000",
      "F192045" => "Intersport",
      "F194045" => "Intersport",
      "F211083" => "Intersport",
      "F211084" => "Intersport",
      "F211118" => "Black store",
      "F212047" => "Black store",
      "F212102" => "Intersport",
      "F212103" => "Intersport",
      "F212109" => "Intersport",
      "F213059" => "Sport 2000",
      "F214102" => "Intersport",
      "F214103" => "Intersport",
      "F214109" => "Intersport",
      "F214118" => "Black store",
      "F217063" => "Sport 2000",
      "F2190021A" => "Intersport",
      "F2190022A" => "Intersport",
      "F221100" => "Black store",
      "F221102" => "Intersport",
      "F221103" => "Black store",
      "F221114" => "Intersport",
      "F221119" => "Sport 2000",
      "F221121" => "Black store,Sport 2000",
      "F222119" => "Black store",
      "F222120" => "Intersport",
      "F222122" => "Black store",
      "F222128" => "Sport 2000",
      "F222138" => "Black store",
      "F223065" => "Sport 2000",
      "F223066" => "Sport 2000",
      "F223150" => "Black store",
      "F223151" => "Black store",
      "F223152" => "Black store",
      "F223156" => "Black store",
      "F224100" => "Black store",
      "F224119" => "Black store",
      "F224120" => "Intersport",
      "F224122" => "Black store",
      "F224128" => "Sport 2000",
      "F224138" => "Black store",
      "F224150" => "Black store",
      "F224151" => "Black store",
      "F224152" => "Black store",
      "F224156" => "Black store,Sport 2000",
      "F225009A" => "Black store",
      "F225010A" => "Intersport",
      "F227078" => "Black store",
      "T19910" => "Sport 2000",
      "T19939" => "Black store,Intersport,Sport 2000",
      "T19949" => "Black store,Intersport,Sport 2000",
      "T19958" => "Black store,Intersport,Sport 2000",
      "T222006" => "Sport 2000",
      "T223000" => "Black store",
      "T224006" => "Sport 2000",
      "T229001" => "Intersport",
      "TF213180" => "Black store",
      "TH2140992" => "Sport 2000",
      "TP21001" => "Intersport",
      "TP21007" => "Black store",
      "TP21008" => "Black store,Intersport,Sport 2000",
      "TP21011" => "Black store,Sport 2000",
      "TP21016" => "Black store,Intersport,Sport 2000",
      "TP21033" => "Intersport",
      "TP21047" => "Black store",
      "TP21056" => "Black store,Sport 2000",
      "TP21058" => "Black store",
      "TP21060" => "Black store",
      "TU212903" => "Sport 2000",
      "TU213900" => "Sport 2000",
      "TU215900" => "Black store",
      "TU216903" => "Sport 2000",
    ];

    $segmentationsBDD = Segmentation::query()->get();
    $segmentationsBDDCheck = [];

    foreach ($segmentationsBDD as $segmentation) {
      $segmentationsBDDCheck[$segmentation->name] = true;
    }


    foreach ($productRefList as $productRef => $segmentations) {
      $products = Product::where("reference", $productRef)->get();
      if (count($products) == 0) {
        echo "******************* Produit inconnu - " . $productRef . "<br>";
      } else {
        $segmentations = explode(",", $segmentations);
        foreach ($segmentations as $segmentation) {
          if (isset($segmentationsBDDCheck[$segmentation])) {
            foreach ($products as $product) {
              $existingProductSegmentation = ProductSegmentation::where("product_id", $product->id)->where("segmentation", $segmentation)->first();
              if (!$existingProductSegmentation) {
                //   ProductSegmentation::create([
                //    "product_id" => $product->id,
                //    "segmentation" => $segmentation
                //  ]);
              } else {
                echo "******************************Segmentation déjà présente - $productRef / $segmentation <br>";
              }
            }
          } else {
            echo "**********Segmentation inconnue - " . $segmentation . "<br>";
          }
        }
      }
    }
  }

  public function searchForProductWithoutMultimarque()
  {
    $productList = Product::get()->filter(function ($product) {
      $hasMultimarque = false;
      foreach ($product->segmentations as $segmentation) {
        if ($segmentation->name == "Multi-marque") {
          $hasMultimarque = true;
          break;
        }
      }
      return !$hasMultimarque;
    });
    $productList->each(function ($product) {
      echo "*************************** $product->reference-$product->color_reference<br>";
      echo "$product->reference-$product->color_reference n'a pas la segmentaion multimarque<br>";
      echo "Ajout de la segmentation...<br>";
      echo $this->addSegmentation($product, "Multi-marque");
    });
  }

  public function logAs()
  {
    if (Auth::user()->id == 28 && Test::testTokenIsProvided()) {
      $newId = Input::get("id");
      Auth::loginUsingId($newId, true);
    } else {
      throw new \Exception("Unauthorized access");
    }
    return redirect()->back();
  }

  public function addImageToProductList()
  {
    $XlsFile = $request::file("debugFile");
    $productList = Excel::load($XlsFile->getRealPath(), function () {
    })->get();
    $excel = Excel::load($XlsFile->getRealPath(), function ($excel) use ($productList) {
      $sheet = $excel->setActiveSheetIndex(0);
      $sheet->setCellValue('A1', "Item");
      $sheet->setCellValue('B1', "Ref");
      $sheet->setCellValue('C1', "Couleur");
      $sheet->setCellValue('D1', "Img");
      $currentLine = 2;
      foreach ($productList as $productXLS) {
        // echo $productXLS->ref . "-" . $productXLS->couleur . " / " .  . " <br>";
        $product = Product::where("reference", $productXLS->ref)
          ->where("color_reference", $productXLS->couleur)
          ->first();
        if (!$product) {
          echo "******** ******** Produit non trouvé : $productXLS->ref-$productXLS->couleur<br>";
        } else {
          $sheet->setCellValue('A' . $currentLine, "$product->reference-$product->color_reference");
          $sheet->setCellValue('B' . $currentLine, $product->reference);
          $sheet->setCellValue('C' . $currentLine, $product->color_reference);
          $sheet->setCellValue('D' . $currentLine, $product->first_image);
          $currentLine++;
        }
      }
    });

    $excel
      ->setFilename('temp')
      ->store('xlsx', storage_path('temp/exports'));
    return Response::download(storage_path('temp/exports/temp.xlsx'))->deleteFileAfterSend(true);
  }

  public function adjustQuantities(Request $request)
  {
    DB::transaction(function () use ($request) {
      $XlsFile = $request::file("debugFile");
      $productList = Excel::load($XlsFile->getRealPath(), function () {
      })->get();

      foreach ($productList as $productXLS) {
        $product = Product::whereRaw("CONCAT(reference, '-', color_reference) = ?", [$productXLS->reference])->first();
        if (!$product) {
          echo "******** ******** Produit non trouvé : $productXLS->reference<br>";
        } else {
          $quantity = intval($productXLS->quantite);
          DB::connection(session('selected_database'))->insert(
            "INSERT INTO product_quantity_changes (
                user_id,
                product_id,
                quantity_before,
                quantity_after,
                type
              )
              VALUES (?, ?, ?, ?, ?)",
            [
              Auth::user()->id,
              $product->id,
              $product->quantity_available,
              $product->quantity_available + $quantity,
              "Retour avoir"
            ]
          );
          echo "$product->reference-$product->color_reference : <br>$product->quantity_available -> ";
          $product->quantity_available = $product->quantity_available + $quantity;
          echo "$product->quantity_available<br>";
          echo "$product->quantity_sold ->";
          $product->quantity_sold = $product->quantity_sold - $quantity;
          echo "$product->quantity_sold<br>";
          $product->save();
          echo "<br><br>";
        }
      }
    });
  }

  public function putProductImagesInStorePath($productList)
  {
    foreach ($productList as $product) {
      $productBDD = Product::getByReference($product["ref"] . "-" . $product["col"]);
      $paths = $productBDD->images_paths;
      foreach ($paths as $key => $path) {
        copy($path, storage_path("temp/images") . "/" . $productBDD->complete_reference . "-" . ($key + 1) . ".jpg");
      }
    }
  }

  public function customersComparison_B2B_logistics()
  {
    $XlsFile = $request::file("debugFile");
    $customersList = Excel::load($XlsFile->getRealPath(), function () {
    })->toArray();
    $customersByClientId = [];
    foreach ($customersList as $customer) {
      if (intval($customer["active"]) === 1) {
        $customersByClientId[$customer["companyid"]][] = $customer["email"];
      }
    }
    $clientErrors = [];
    $clientNotFound = [];
    foreach ($customersByClientId as $clientId => $emails) {
      $client = Client::find($clientId);
      if (!$client) {
        $clientNotFound[$clientId] = "Client non trouvé - $clientId";
      } else {
        if (count($client->users) !== count($emails)) {
          $clientErrors[$clientId] = [
            "name" => $client->name,
            "reference" => $client->reference,
            "b2b" => $emails,
            "logistics" => $client->users->map(function ($user) {
              return $user->username;
            })->toArray()
          ];
        }
      }
    }
    dd($clientErrors);
  }

  public function xslExport()
  {
    // foreach ($productsList as $product) {

    // }

    // $excel = Excel::load($XlsFile->getRealPath(), function ($excel) use ($productList) {
    //   $sheet = $excel->setActiveSheetIndex(0);
    //   $sheet->setCellValue('A1', "Item");
    //   $sheet->setCellValue('B1', "Ref");
    //   $sheet->setCellValue('C1', "Couleur");
    //   $sheet->setCellValue('D1', "Img");
    //   $currentLine = 2;
    //   foreach ($productList as $productXLS){
    //     // echo $productXLS->ref . "-" . $productXLS->couleur . " / " .  . " <br>";
    //     $product = Product::where("reference", $productXLS->ref)
    //       ->where("color_reference", $productXLS->couleur)
    //       ->first();
    //     if (!$product) {
    //       $sheet->setCellValue('A' . $currentLine, "$productXLS->ref-$productXLS->couleur");
    //       $sheet->setCellValue('B' . $currentLine, $productXLS->ref);
    //       $sheet->setCellValue('C' . $currentLine, $productXLS->couleur);
    //       // echo "******** ******** Produit non trouvé : $productXLS->ref-$productXLS->couleur<br>";
    //     } else{
    //       $sheet->setCellValue('A' . $currentLine, "$product->reference-$product->color_reference");
    //       $sheet->setCellValue('B' . $currentLine, $product->reference);
    //       $sheet->setCellValue('C' . $currentLine, $product->color_reference);
    //       $sheet->setCellValue('D' . $currentLine, $product->first_image);
    //     }
    //     $currentLine++;

    //   }

    // });

    // $excel
    //   ->setFilename('temp')
    //   ->store('xlsx', storage_path('temp/exports'));
    // return Response::download(storage_path('temp/exports/temp.xlsx'))->deleteFileAfterSend(true);
  }

  public function quantityModifications()
  {
    DB::transaction(function () use ($productList) {
      foreach ($productList as $productXLS) {
        $product = Product::getByReference($productXLS["reference"]);
        if (!$product) {
          echo "Produit introuvable : " . $productXLS["reference"];
        } else {


          // $followingChanges = ProductQuantityChange::where("product_id", $product->id)
          //   ->where("created_at", ">=", $arrivalChange->created_at)
          //   ->get();

          // echo $product->complete_reference . " // " . $product->id . " => " . $productXLS["diff"] . "<br>";
          // foreach ($followingChanges as $change) {
          //   echo "---- Change<br>";
          //   echo "-------- Date : " . $change->created_at . "<br>";
          //   if ($change->created_at !== $arrivalChange->created_at){
          //     echo "-------- Quantity_before : " . $change->quantity_before . " =>" . ($change->quantity_before - intval($productXLS["diff"])) .  "<br>";
          //     $change->quantity_before -= intval($productXLS["diff"]) * 2;
          //   }
          //   echo "-------- Quantity_after : " . $change->quantity_after . " =>" . ($change->quantity_after - intval($productXLS["diff"])) .  "<br>";
          //   $change->quantity_after -= intval($productXLS["diff"]) * 2;
          //   $change->save();
          // }
          // // $product->quantity_available += intval($productXLS["diff"]);
          // $product->save();
          // echo "<br>";
        }
      }
    });
  }

  public function addImgUrl()
  {
    $XlsFile = $request::file("debugFile");
    $file = Excel::load($XlsFile->getRealPath(), function ($excel) {
      // $excel->getSheetNames();
      foreach ($excel->getSheetNames() as $sheetName) {
        $sheet = $excel->setActiveSheetIndexByName($sheetName);
        $productReference = "start";
        $line = 2;
        while ($productReference && $line < 2000) {
          $productReference = $excel->getActiveSheet()->getCell('A' . $line)->getValue();
          if ($productReference) {
            $product = Product::getByReference($productReference);
            if ($product) {
              // echo $product->first_image;
              $sheet->setCellValue('B' . $line, $product->first_image);
            } else {
              // echo "Produit introuvable : ". $productReference;
            }
            // echo "<br>";
          }
          $line++;
        }
      }
    })->export("xls");
  }

  public function modifiyClientNotation()
  {
    $XlsFile = $request::file("debugFile");
    // $XlsFile->getPath()
    $clientList = Excel::load($XlsFile->getRealPath(), function () {
    })->get();

    foreach ($clientList as $clientXLS) {
      $client = Client::where("reference", $clientXLS->code_client)->first();
      if ($client) {
        if ($client->name === $clientXLS->nom) {
        } else {
          echo "******** ******** Client $client->reference erreur nom : $clientXLS->nom / $client->name<br>";
        }
        if (intval($client->notation) != intval($clientXLS->notation)) {
          echo "$client->reference : $client->notation -> $clientXLS->notation<br>";
        }
        $client->notation = intval($clientXLS->notation);
        $client->save();
      } else {
        echo "******** ******** Client non trouvé : " . $clientXLS->code_client . "<br>";
      }
    }
  }

  public function importProductFromXLS()
  {
    DB::transaction(function () {

      // A traiter à part : 
      $specialKeys = [
        "tags" => "tags",
        "segmentations" => "segmentations",
        "sizing_pack" => "sizing_pack",
        "season" => "season",
        "collection" => "true_collection",
        "categorie" => "collection",
        "designation" => "category",
        "consommateur" => "consumer_category",
        "coupe" => "cut",
        "tva_code",
      ];

      $ignoreKeys = [
        "products_per_pack_quantity" => "products_per_pack_quantity",
        "quantity_available" => "quantity_available",
        "quantity_sold" => "quantity_sold",
        "warehouse_location" => "warehouse_location",
        "image" => "image",
        "created_at" => "created_at",
        "deleted_at" => "deleted_at",
        "product_type" => "product_type",
      ];

      $productKeys = [
        // Special keys
        "tags" => "tags",
        "segmentations" => "segmentations",
        "sizing_pack" => "sizing_pack",
        "season" => "season",
        "collection" => "true_collection",
        "categorie" => "collection",
        "designation" => "category",
        "consommateur" => "consumer_category",
        "coupe" => "cut",


        // Ignore keys
        "products_per_pack_quantity" => "products_per_pack_quantity",
        "quantity_available" => "quantity_available",
        "quantity_sold" => "quantity_sold",
        "warehouse_location" => "warehouse_location",
        "image" => "image",
        "created_at" => "created_at",
        "deleted_at" => "deleted_at",
        "product_type" => "product_type",

        // Normal keys
        "reference" => "reference",
        "color_reference" => "color_reference",
        "color_name" => "color_name",
        "ean_pack" => "ean_pack",
        "name" => "name",
        "date_dintroduction" => "release_date",
        "composition" => "composition",
        "description" => "description",
        "color_description" => "color_description",
        "weight" => "weight",
        "notes" => "notes",


        "operations_list_price" => "operations_list_price",
        "operations_outlet_price" => "operations_outlet_price",
        "unit_price" => "unit_price",
        "unit_price_reduced" => "unit_price_reduced",
        "unit_price_outlet" => "unit_price_outlet",
        "list_price" => "list_price",
        "promo_b2b" => "promo_b2b",


        "is_present_catalog" => "is_present_catalog",
        "is_shooted_ghost" => "is_shooted_ghost",
        "is_shooted_worn" => "is_shooted_worn",
        "is_shop_only" => "is_shop_only",
        "is_hidden" => "is_hidden",
        "is_hidden_store" => "is_hidden_store",
        "is_blocked" => "is_blocked",


        "customs_code" => "customs_code",
        "origin" => "origin",
        "tva_code" => "tva_code",
        "b2b_sort_date" => "b2b_sort_date",

      ];

      $XlsFile = Input::file("debugFile");
      $excel = Excel::load($XlsFile->getRealPath())->toArray();


      function getSkuEan($excel, $reference, $color_reference, $size_label)
      {
        foreach ($excel[1] as $index => $product) {
          if ($product["reference"] === $reference && $product["color_reference"] === $color_reference && $product["size_id"] === $size_label) {
            return $product["ean_sku"];
          }
        }
        return null;
      }

      $tagsList = ProductTag::all();
      $tagsListByName = [];
      foreach ($tagsList as $tag) {
        $tagsListByName[$tag->name] = $tag->id;
      }

      $productCategoriesList = ProductCategory::all();
      $productCategoriesListByName = [];
      foreach ($productCategoriesList as $productCategory) {
        $productCategoriesListByName[$productCategory->name] = $productCategory->id;
      }

      $consumerCategoriesList = ConsumerCategory::all();
      $consumerCategoriesListByName = [];
      foreach ($consumerCategoriesList as $consumerCategory) {
        $consumerCategoriesListByName[$consumerCategory->name] = $consumerCategory->id;
      }

      $segmentationsList = Segmentation::all()->map(function ($seg) {
        return $seg->name;
      })->toArray();

      $tvaCodes = [
        20 => 1,
        5.5 => 2,
        0 => 3,
        "" => 1,
      ];

      $errors = [];
      foreach ($excel[0] as $productToCreate) {
        $productCreationData = [];
        foreach (array_keys($productToCreate) as $key) {
          $realKey = $productKeys[$key];
          if (isset($ignoreKeys[$key])) continue;
          if (!isset($specialKeys[$key])) {
            $productCreationData[$realKey] = $productToCreate[$key];
          }
        }
        // "season" => "season",
        $season = explode("-", $productToCreate["season"]);
        $productCreationData["season_season"] = $season[1];
        $productCreationData["season_year"] = $season[0];

        // "collection" => "true_collection",
        $true_collection = $productToCreate["collection"];
        if (!isset($productCategoriesListByName[$true_collection])) {
          echo "******** ******** Categorie non trouvée (" . $productToCreate["reference"] . "-" . $productToCreate["color_reference"] . "): " . $true_collection . "<br>";
        } else {
          $productCreationData["true_collection"] = $productCategoriesListByName[$true_collection];
        }


        // "categorie" => "collection",
        $collection = $productToCreate["categorie"];
        if (!isset($productCategoriesListByName[$collection])) {
          echo "******** ******** Categorie non trouvée (" . $productToCreate["reference"] . "-" . $productToCreate["color_reference"] . "): " . $collection . "<br>";
        } else {
          $productCreationData["collection"] = $productCategoriesListByName[$collection];
        }

        // "designation" => "category",
        $category = $productToCreate["designation"];
        if (!isset($productCategoriesListByName[$category])) {
          echo "******** ******** designation non trouvée (" . $productToCreate["reference"] . "-" . $productToCreate["color_reference"] . "): " . $category . "<br>";
        } else {
          $productCreationData["category"] = $productCategoriesListByName[$category];
        }

        // "coupe" => "cut",
        $cut = $productToCreate["coupe"];
        if (!isset($productCategoriesListByName[$cut])) {
          echo "******** ******** coupe non trouvée (" . $productToCreate["reference"] . "-" . $productToCreate["color_reference"] . "): " . $cut . "<br>";
        } else {
          $productCreationData["cut"] = $productCategoriesListByName[$cut];
        }

        // "consommateur" => "consumer_category",
        $consumer_category = $productToCreate["consommateur"];
        if (!isset($consumerCategoriesListByName[$consumer_category])) {
          echo "******** ******** consommateur non trouvée (" . $productToCreate["reference"] . "-" . $productToCreate["color_reference"] . "): " . $consumer_category . "<br>";
        } else {
          $productCreationData["consumer_category"] = $consumerCategoriesListByName[$consumer_category];
        }

        $productCreationData["tva_code"] = isset($tvaCodes[$productToCreate["tva_code"]]) ? $tvaCodes[$productToCreate["tva_code"]] : 1;


        try {
          $product = Product::create($productCreationData);
        } catch (\Exception $e) {
          traceLog::logException($e);
          $errors[] = $e->getMessage();
          continue;
        }



        // "sizing_pack" => "sizing_pack",
        $sizes = array_filter(array_map(function ($size) {
          if ($size === "") return null;
          return explode(":", $size);
        }, explode(" ", $productToCreate["sizing_pack"])), function ($size) {
          return $size != null;
        });

        foreach ($sizes as $size) {
          $sizeBDD = Size::where("label", $size[0])->first();
          if (!$sizeBDD) {
            echo "size error : taille non trouvée : " . $size[0] . "<br>";
          }
          $skuEan = getSkuEan($excel, $productToCreate["reference"], $productToCreate["color_reference"], $size[0]);
          PackItem::create([
            'product_id' => $product->id,
            'size_id' => $sizeBDD->id,
            'quantity' => $size[1],
            'ean' => $skuEan,
            'pieces_available' => 0
          ]);
        }

        // "tags" => "tags",

        $tags = explode(",", $productToCreate["tags"]);
        foreach ($tags as $tag) {
          $tag = trim($tag);
          if ($tag === "") continue;
          if (!isset($tagsListByName[$tag])) {
            echo "******** ******** Tag non trouvé ($product->complete_reference): " . $tag . "<br>";
          } else {
            ProductsProductTag::create([
              "product_id" => $product->id,
              "product_tag_id" => $tagsListByName[$tag]
            ]);
          }
        }


        // "segmentations" => "segmentations",
        $segmentations = explode(",", $productToCreate["segmentations"]);
        foreach ($segmentations as $segmentation) {
          if (array_search($segmentation, $segmentationsList) === false) {
            echo "******** ******** Segmentation non trouvée ($product->complete_reference): " . $segmentation . "<br>";
          } else {
            ProductSegmentation::create([
              "product_id" => $product->id,
              "segmentation" => $segmentation
            ]);
          }
        }
      }
      DebugLog::logJson("Imports XLS produits - errors " . count($errors), $errors);
    });
  }

  public function restorePiecesStock()
  {
    $XlsFile = Input::file("debugFile");
    $excel = Excel::load($XlsFile->getRealPath())->toArray();
    $restoredPiecesStocks = [];
    foreach ($excel as $pieceXLS) {
      $piece = PackItem::find($pieceXLS["piece_id"]);
      $locations = $piece
        ->product
        ->locations
        ->map(function ($location) {
          return $location->location;
        })
        ->filter(function ($location) {
          return strpos($location, "U") === false;
        });
      if (count($locations) > 0 && intval($pieceXLS["quantity"]) > 0) {
        $restoredPiecesStocks[] = array_merge($pieceXLS, ["locations" => $locations->implode(",")]);
        $mainStock = $piece->getMainWarehouseStock();
        $mainStock->quantity = $pieceXLS["quantity"];
        $mainStock->save();
        $piece->updateAvailableQuantity();
        echo $piece->complete_reference . " : " . $pieceXLS["quantity"] . "<br>";
      };
    }
    DebugLog::logJson("Restored pieces quantities", $restoredPiecesStocks);
    dd($restoredPiecesStocks);
  }


  public function createClients($clientsToAdd, $options = [])
  {
    // $options["propertyMapping"] peut contenir un tableau associatif pour mapper les propriétés    
    // Par exemple : [ "commercial" => "agentName", ... ]
    // $options["addressIsSellingPoint"] = boolean

    // "agent_name"
    // "client_name"
    // "activity"
    // "notation"
    // "address_name" - si non fourni, "client_name" est utilisé
    // "street"
    // "post_code"
    // "city"
    // "country"
    // "last_name"
    // "first_name"
    // "role"
    // "email"
    // "notes" commentaire à ajouter au client
    // "consumers" -> séparé par un "/"


    DB::transaction(function () use ($clientsToAdd, $options) {
      foreach ($clientsToAdd as $clientToAdd) {
        foreach ($clientToAdd as $key => $value) {
          $clientToAdd[$key] = trim($value);
        }
        if (isset($options["propertyMapping"])) {
          foreach ($options["propertyMapping"] as $property => $newProperty) {
            $clientToAdd[$newProperty] = $clientToAdd[$property];
          }
        }

        if ($clientToAdd["client_name"] === "" || $clientToAdd["client_name"] === null) continue;
        $agent = false;
        if (isset($clientToAdd["agent_name"])) {
          $agent = User::where("name", $clientToAdd["agent_name"])->first();
          if (!$agent) {
            echo "Commercial introuvable : "  . $clientToAdd["agent_name"] . " (" . $clientToAdd["client_name"] . ")<br>";
          }
        }
        $client = Client::where("name", $clientToAdd["client_name"]);
        if ($agent) {
          $client = $client->where("agent_id", $agent->id);
        }
        $client = $client->first();
        if (!$client) {
          switch (trim($clientToAdd["country"])) {
            case "Scotland":
              $clientToAdd["country"] = "United Kingdom";
              break;
            case "England":
              $clientToAdd["country"] = "United Kingdom";
              break;
            case "UK":
              $clientToAdd["country"] = "United Kingdom";
              break;
            case "USA":
              $clientToAdd["country"] = "United States";
              break;
            case "S KOREA":
              $clientToAdd["country"] = "Korea, Republic of";
              break;
            case "LEBANON/JORDAN":
              $clientToAdd["country"] = "Lebanon";
              break;
            case "KUWAIT/BAHREIN/QATAR":
              $clientToAdd["country"] = "Qatar";
              break;
            case "UAE":
              $clientToAdd["country"] = "United Arab Emirates";
              break;
            case "TAIWAN":
              $clientToAdd["country"] = "Taiwan, Province of China";
              break;
            case "DENMARK/SWEDEN/NORWAY/ICELAND":
              $clientToAdd["country"] = "Sweden";
              break;
            case "KSA":
              $clientToAdd["country"] = "Saudi Arabia";
              break;
            case "HK - CHINA":
              $clientToAdd["country"] = "Hong Kong";
              break;
            case "SINGAPORE/HK":
              $clientToAdd["country"] = "Singapore";
              break;
            case "VIETNAM":
              $clientToAdd["country"] = "Viet Nam";
              break;
            case "NEDERLAND":
              $clientToAdd["country"] = "Netherlands";
              break;
          }

          switch (trim($clientToAdd["client_name"])) {
            case "LA GARCONNE":
              $clientToAdd["country"] = "United States";
              break;
          }

          $country = Country::where(DB::raw("LOWER(name)"), strtolower(trim($clientToAdd["country"])))->first();
          if (!$country) {
            echo "Country error<br>";
            dd($clientToAdd);
          }

          $client = Client::create([
            "name" => trim($clientToAdd["client_name"]),
            "notation" => isset($clientToAdd["notation"]) ? trim($clientToAdd["notation"]) : 3,
            "vat_number" => isset($clientToAdd["vat_number"]) ? trim($clientToAdd["vat_number"]) : 3,
            "agent_id" => $agent ? $agent->id : null,
            "country" => ucfirst(strtolower($clientToAdd["country"])),
          ]);

          if ($clientToAdd["city"] === null || $clientToAdd["city"] === "") {
            $clientToAdd["city"] = "A compléter";
            echo "City error : " . $clientToAdd["client_name"] . "<br>";
          }
          $addressIsSellingPoint = isset($options["addressIsSellingPoint"]) ? $options["addressIsSellingPoint"] : false;

          // Addresses
          Address::create([
            'note' =>  isset($clientToAdd["address_name"]) ? trim($clientToAdd["address_name"]) : trim($clientToAdd["client_name"]),
            'street' => isset($clientToAdd["street"]) ? trim($clientToAdd["street"]) : "",
            'post_code' => isset($clientToAdd["post_code"]) ? trim($clientToAdd["post_code"]) : "",
            'city' => trim($clientToAdd["city"]),
            'country_id' => $country->id,
            'addressable_id' => $client->id,
            'addressable_type' => Client::class,
            'is_billing' => 1,
            'is_shipping' => $addressIsSellingPoint ? 1 : 0,
            'edi_gln' => isset($clientToAdd["edi_gln"]) ? trim($clientToAdd["edi_gln"]) : ""
          ]);

          if (isset($clientToAdd["notes"])) {
            ClientComment::create([
              'client_id' => $client->id,
              'user_id' => $agent,
              'content' => trim($clientToAdd["notes"]),
              'date' => date("Y-m-d H:i:s"),
            ]);
          }
        }

        // Contacts
        $contact = Contact::create([
          'client_id' => $client->id,
          'firstname' => isset($clientToAdd["first_name"]) ? trim($clientToAdd["first_name"]) : "",
          'lastname' => isset($clientToAdd["last_name"]) ? trim($clientToAdd["last_name"]) : "",
          'role' => isset($clientToAdd["role"]) ? trim($clientToAdd["role"]) : "",
          'email' => isset($clientToAdd["email"]) ? trim($clientToAdd["email"]) : ""
        ]);
        $client->contacts()->save($contact);
        $contact->save();

        // Consommateurs
        if (!isset($clientToAdd["consumers"])) {
          $consumers = [];
        } else {
          $consumers = explode("/", trim($clientToAdd["consumers"]));
        }
        foreach ($consumers as $consumer) {
          $consumerCategory = ConsumerCategory::where("name", trim($consumer))->first();
          if (!$consumerCategory) {
            echo "Consommateur introuvable : " . trim($consumer);
            dd($clientToAdd);
          }
          $clientConsumer = ClientConsumer::where("client_id", $client->id)->where("consumer_category_id", $consumerCategory->id)->first();
          if (!$clientConsumer) {
            ClientConsumer::create([
              "client_id" => $client->id,
              "consumer_category_id" => $consumerCategory->id,
            ]);
          }
        }
      }
    });
  }

  public function createClientsFromXls()
  {
    return "Ajouter la vérification des numéros de tva et de siret pour éviter les doublons";

    // "commercial_en_charge" => "Collection X"
    // "nom_client" => "10 corso com"
    // "activite" => "Prospect"
    // "notation" => null
    // "nom" => "Bertocchi"
    // "ville" => "MILANO"
    // "pays" => "ITALY "
    // "prenom" => "Silvia"
    // "role" => "Fashion and Merchandising Director"
    // "email" => "silviab@10corsocomo.com"
    // "notes" => "Collection X prospect; Company type = MULTI+ONLINE; Store count = ; EUROPE; https://10corsocomo.com/it; Gender = M+W"



    // to fill : 
    // 'notation',
    // 'company_name',
    // 'agent_id

    // billing address : ajouter une addresse avec is billing = 1

    // 'city',
    // 'country_id',
    // 'addressable_id',
    // 'addressable_type',
    // 'is_billing',

    // commercial : associer user
    // "agent_id"

    // commentaires : client_comments
    // 'client_id',
    // 'user_id',
    // 'date',
    // 'content'

    // contacts: Contact
    // 'client_id' => 
    // 'firstname' => 
    // 'lastname' => 
    // 'role' => 
    // 'email' => 
    // 'landline_phone' => 
    // 'mobile_phone' => 
    // 'is_invoice_receiver' => 




    $XlsFile = Input::file("debugFile");
    $clientsToAdd = Excel::load($XlsFile->getRealPath())->toArray();
    DB::transaction(function () use ($clientsToAdd) {
      foreach ($clientsToAdd as $clientToAdd) {
        if ($clientToAdd["nom_client"] === "" || $clientToAdd["nom_client"] === null) continue;
        $agent = User::where("name", $clientToAdd["commercial_en_charge"])->first();
        if (!$agent) {
          echo "Commercial introuvable : "  . $clientToAdd["commercial_en_charge"] . "(" . $clientToAdd["nom_client"] . ")<br>";
        }
        $client = Client::where("name", $clientToAdd["nom_client"])->where("agent_id", $agent->id)->first();
        if (!$client) {
          switch (trim($clientToAdd["pays"])) {
            case "UK":
              $clientToAdd["pays"] = "United Kingdom";
              break;
            case "USA":
              $clientToAdd["pays"] = "United States";
              break;
            case "S KOREA":
              $clientToAdd["pays"] = "Korea, Republic of";
              break;
            case "LEBANON/JORDAN":
              $clientToAdd["pays"] = "Lebanon";
              break;
            case "KUWAIT/BAHREIN/QATAR":
              $clientToAdd["pays"] = "Qatar";
              break;
            case "UAE":
              $clientToAdd["pays"] = "United Arab Emirates";
              break;
            case "TAIWAN":
              $clientToAdd["pays"] = "Taiwan, Province of China";
              break;
            case "DENMARK/SWEDEN/NORWAY/ICELAND":
              $clientToAdd["pays"] = "Sweden";
              break;
            case "KSA":
              $clientToAdd["pays"] = "Saudi Arabia";
              break;
            case "HK - CHINA":
              $clientToAdd["pays"] = "Hong Kong";
              break;
            case "SINGAPORE/HK":
              $clientToAdd["pays"] = "Singapore";
              break;
            case "VIETNAM":
              $clientToAdd["pays"] = "Viet Nam";
              break;
          }

          switch (trim($clientToAdd["nom_client"])) {
            case "LA GARCONNE":
              $clientToAdd["pays"] = "United States";
              break;
          }

          $country = Country::where(DB::raw("LOWER(name)"), strtolower(trim($clientToAdd["pays"])))->first();
          if (!$country) {
            echo "Country error<br>";
            dd($clientToAdd);
          }

          $client = Client::create([
            "name" => trim($clientToAdd["nom_client"]),
            "notation" => trim($clientToAdd["notation"]) ?: 3,
            "agent_id" => $agent->id,
            "country" => ucfirst(strtolower($clientToAdd["pays"])),
          ]);

          if ($clientToAdd["ville"] === null || $clientToAdd["ville"] === "") {
            $clientToAdd["ville"] = "A compléter";
            echo "City error : " . $clientToAdd["nom_client"] . "<br>";
          }
          Address::create([
            'city' => trim($clientToAdd["ville"]),
            'country_id' => $country->id,
            'addressable_id' => $client->id,
            'addressable_type' => Client::class,
            'is_billing' => 1,
          ]);
          ClientComment::create([
            'client_id' => $client->id,
            'user_id' => $agent,
            'content' => trim($clientToAdd["notes"]),
            'date' => date("Y-m-d H:i:s"),
          ]);
        }
        $contact = Contact::create([
          'client_id' => $client->id,
          'firstname' => trim($clientToAdd["prenom"]),
          'lastname' => trim($clientToAdd["nom"]),
          'role' => trim($clientToAdd["role"]),
          'email' => trim($clientToAdd["email"])
        ]);
        $client->contacts()->save($contact);
        $contact->save();
      }
    });
  }

  public function displayParsedEdiInvoice()
  {
    $file = Input::file("debugFile");
    dd(EdiHelper::parseFile($file->getRealPath(), [
      "fileStructureType" => EdiProperty::FILE_TYPE_POSITIONNED,
      "grouped" => true
    ]));
  }

  public function addLocationsToExport()
  {
    $XlsFile = $request::file("debugFile");
    $excel = Excel::load($XlsFile->getRealPath(), function ($excel) {
      $sheet = $excel->setActiveSheetIndex(0);
      $excelArray = $sheet->toArray();
      $maxRow = intval($sheet->getHighestRow());
      $startLine = 1;
      for ($currentLine = $startLine; $currentLine < $maxRow; $currentLine++) {
        $product = Product::getByReference($excelArray[$currentLine][0] . "-" . $excelArray[$currentLine][1]);
        if (!$product) {
          $sheet->setCellValue("L" . ($currentLine + 1), "Produit introuvable dans la BDD");
        } else {
          if (count($product->sorted_locations) > 0) {
            $locations = implode(",", $product->sorted_locations);
          } else {
            $locations = "-";
          }
          $sheet->setCellValue("L" . ($currentLine + 1), $locations);
        }
      }
    });


    $excel
      ->setFilename("temp")
      ->store('xlsx', storage_path('temp/exports'));
    return Response::download(storage_path('temp/exports/temp.xlsx'))->deleteFileAfterSend(true);
  }

  public function uploadTmpFile()
  {
    return view("debug.upload");
  }

  public function updateSegmentations($referencesList)
  {
    $notFound = 0;
    foreach ($referencesList as $reference) {
      if ($reference["references"] === "") continue;
      echo "<br><br>";
      echo "<br>****************************************************************";
      echo "<br>***********************  NEW REFERENCE  ************************";
      echo "<br>****************************************************************<br><br>";
      $products = Product::where("reference", $reference["references"])->get();
      if (count($products) === 0) {
        $notFound++;
        echo "********************************************************************* Produits introuvables : " . $reference["references"] . "<br>";
      } else {
        echo "Reference " . $reference["references"] . " : " . count($products) . "<br>";
        $segmentationsToAdd = explode(",", $reference["segmentations"]);
        echo '<pre>$segmentationsToAdd<br />';
        var_dump($segmentationsToAdd);
        echo '</pre>';
        echo "---- Segs : " . count($segmentationsToAdd) . "<br>";
        foreach ($segmentationsToAdd as $segmentationStr) {
          $segmentationBdd = Segmentation::where("name", $segmentationStr)->first();
          if (!$segmentationBdd) {
            echo "<br>Segmentation introuvable : " . $segmentationStr . "<br>";
          }
          foreach ($products as $product) {
            $product->addSegmentation($segmentationStr);
            echo "***********************  SEGMENTATIONS REMOVAL  ************************";
            foreach ($product->segmentations as $existingSegmentation) {
              echo '<pre>Product segmentation<br />';
              var_dump($existingSegmentation->name);
              echo '</pre>';
              echo '<pre>array_search($existingSegmentation->name, $segmentationsToAdd)<br />';
              var_dump(array_search($existingSegmentation->name, $segmentationsToAdd));
              echo '</pre>';
              if (array_search($existingSegmentation->name, $segmentationsToAdd) === false) {
                $product->removeSegmentation($existingSegmentation->name);
              }
            }
          }
        }
      }
    }
    if ($notFound) {
      echo "<br><br><br><br><br>";
      echo "Not found : " . $notFound;
    }
  }

  public function version()
  {
    return "Laravel " . app()->version();
  }

  public function phpInfos()
  {
    return phpinfo();
  }

  public function orderProductsList()
  {
    $orderId = Input::get("orderId");
    $order = Order::find($orderId);
    if (!$order) {
      echo "commande inconnu";
      return;
    }
    echo "!!! Seuls les produits référencés sont listés ici !!!<br><br>";

    foreach ($order->items as $item) {
      echo "id : $item->product_id<br>";
    }
  }

  public function checkQuantityChange()
  {
    $productId = Input::get('product_id');
    $product = Product::find($productId);
    if (!$product) {
      echo "Produit inconnu";
      return;
    } else {
      echo "Produit trouvé : " . $product->reference . "-" . $product->color_reference;
      echo "<br>";
    }
    $dateTime = Input::get('date_time');
    $productQuantityChangeToDelete = ProductQuantityChange::where('created_at', $dateTime)
      ->where("product_id", $productId)
      ->get();
    if ($productQuantityChangeToDelete->count() == 0) {
      echo "No productQuantityChange found";
      return;
    }
    if ($productQuantityChangeToDelete->count() > 1) {
      echo "Too many productQuantityChanges";
      return dd($productQuantityChangeToDelete);
    }
    $productQuantityChangeToDelete = $productQuantityChangeToDelete->first();
    echo '<pre>$productQuantityChangeToDelete<br />';
    var_dump($productQuantityChangeToDelete);
    echo '</pre>';

    $quantityVariation = $productQuantityChangeToDelete->quantity_before - $productQuantityChangeToDelete->quantity_after;
    echo '<pre>$quantityVariation<br />';
    var_dump($quantityVariation);
    echo '</pre>';

    ProductQuantityChange::where('created_at', '>',  $dateTime)
      ->where("product_id", $productId)
      ->orderBy("created_at")
      ->get();
    echo 'Data to adjust : ';
    dd(ProductQuantityChange::where('created_at', '>',  $dateTime)
      ->where("product_id", $productId)
      ->orderBy("created_at")
      ->get());

    echo "Done !";
  }


  public function deleteAndAdjustStockChange()
  {
    $productId = Input::get('product_id');
    $product = Product::find($productId);
    if (!$product) {
      echo "Produit inconnu";
      return;
    } else {
      echo "Produit trouvé : " . $product->reference . "-" . $product->color_reference;
      echo "<br>";
    }
    $dateTime = Input::get('date_time');
    $productQuantityChangeToDelete = ProductQuantityChange::where('created_at', $dateTime)
      ->where("product_id", $productId)
      ->get();
    if ($productQuantityChangeToDelete->count() == 0) {
      echo "No productQuantityChange found";
      return;
    }
    if ($productQuantityChangeToDelete->count() > 1) {
      echo "Too many productQuantityChanges";
      return dd($productQuantityChangeToDelete);
    }
    $productQuantityChangeToDelete = $productQuantityChangeToDelete->first();
    $quantityVariation = $productQuantityChangeToDelete->quantity_before - $productQuantityChangeToDelete->quantity_after;
    echo '<pre>$quantityVariation<br />';
    var_dump($quantityVariation);
    echo '</pre>';
    ProductQuantityChange::where('created_at', '>',  $dateTime)
      ->where("product_id", $productId)
      ->orderBy("created_at")
      ->get()
      ->each(function ($productQuantityChangeToAdjust) use ($quantityVariation) {
        $productQuantityChangeToAdjust->quantity_before = $productQuantityChangeToAdjust->quantity_before + $quantityVariation;
        $productQuantityChangeToAdjust->quantity_after = $productQuantityChangeToAdjust->quantity_after + $quantityVariation;
        $productQuantityChangeToAdjust->save();
      });

    $productQuantityChangeToDelete->delete();
    echo "Done !";
  }

  public function modifyCreditsCategory()
  {
    $conversionTable = [
      "Erreur Client" => "Accord commercial",
      "Retour commercial" => "Accord commercial",
      "Erreur facturation commerciaux" => "Accord commercial",
      "Défaut Usine" => "Défectueux",
      "Défectueux" => "Défectueux",
      "dotation" => "dotation",
      "Erreur facturation" => "Erreur ADV",
      "Erreur facturation ADV" => "Erreur ADV",
      "Erreur livraison PXP" => "Erreur ADV",
      "Erreur préparation" => "Erreur préparation",
      "Paiement Comptant" => "Escompte",
      "Colis perdu" => "Problème transport",
      "Erreur livraison Transporteur" => "Problème transport",
      "Aucun" => "-",
      "Retour Marchandise" => "Autre",
    ];

    $creditItems = CreditItem::whereNotNull('category_id')->get();
    $unsetReasons = [];
    foreach ($creditItems as $creditItem) {
      if ($creditItem->category) $category = $creditItem->category->name;
      else $category = "No cat.";
      if (isset($conversionTable[$category])) {
        $newCategory = CreditCategory::where("name", $conversionTable[$category])->first();
        if (!$newCategory) {
          $newCategory = CreditCategory::create(["name" => $conversionTable[$category]]);
        }

        $creditItem->category_id = $newCategory->id;
        $creditItem->save();

        // $creditItem->credit_reason = $conversionTable[$creditItem->credit_reason];
        // $creditItem->save();
      } else {
        if (!isset($unsetReasons[$category])) {
          $unsetReasons[$category] = 1;
        } else {
          $unsetReasons[$category]++;
        }
      }
    }

    // Suppression des anciennes categories
    $oldCategories = array_keys($conversionTable);
    foreach ($oldCategories as $oldCategory) {
      $isToDelete = array_search($oldCategory, $conversionTable) === false;
      if ($isToDelete) {
        echo $oldCategory . " à supprimer<br>";
        $categoryToDelete = CreditCategory::where("name", $oldCategory)->first();
        if ($categoryToDelete) {
          $categoryToDelete->delete();
        }
      }
    }

    dd($unsetReasons);
  }

  public function b2bflowsErrorsViewer()
  {
    $cronTypes = CronLog::select(DB::raw("DISTINCT name"))
      ->where("name", "not like", "%b2b%")
      ->orderBy("name", "ASC")
      ->get()
      ->map(function ($type) {
        return $type->name;
      })->toArray();
    array_unshift($cronTypes, "B2B");
    // $b2bCronTypes = CronLog::select(DB::raw("DISTINCT name"))->where("name", "like", "%b2b%")->get();
    $keyword = Input::get('keyword');
    $crons = CronLog::query()
      // ->where("name", "like", "b2b%")
      ->orderBy("time", "DESC")
      ->orderBy("id", "DESC");

    $startDate = Input::get('startDate');
    $endDate = Input::get('endDate');
    $cronType = Input::get('cronType');

    if ($cronType === null || $cronType === "B2B") {
      $cronType = "B2B%";
    }

    $includesStatus = [
      "includeFailed" => ["status" => CronLogStatus::FAILED],
      "includeFailedRetry" => ["status" => CronLogStatus::FAILED_RETRY],
      "includeRefineTest" => ["status" => CronLogStatus::REFINE_TEST],
      "includePartialSuccess" => ["status" => CronLogStatus::PARTIAL_SUCCESS],
      "includeSuccess" => ["status" => CronLogStatus::SUCCEEDED],
      "includeProductImgErrors" => ["status" => CronLogStatus::PRODUCT_IMG_ERRORS],
      "includeProductIntegrityErrors" => ["status" => CronLogStatus::PRODUCT_INTEGRITY_ERRORS],
    ];

    foreach ($includesStatus as $inputKey => $status) {
      $includesStatus[$inputKey]["isToInclude"] = Input::get($inputKey) ? Input::get($inputKey) === "on" : false;
    }

    $checkedType = Input::get('checkedType');
    if (!$checkedType) {
      $checkedType = "logUnchecked";
    }

    if (!$includesStatus["includeFailed"] && !$includesStatus["includePartialSuccess"] && !$includesStatus["includeSuccess"] && !$includesStatus["includeFailedRetry"] && !$includesStatus["includeRefineTest"]) {
      $includesStatus["includeFailed"]["isToInclude"] = true;
      $includesStatus["includeFailedRetry"]["isToInclude"] = true;
      $includesStatus["includePartialSuccess"]["isToInclude"] = true;
    }

    if ($checkedType == "logChecked") {
      $crons = $crons->where("checked", 1);
    } else if ($checkedType == "logUnchecked") {
      $crons = $crons->where(function ($query) {
        $query = $query->orWhereNull("checked")
          ->orWhere("checked", 0);
      });
    }

    if ($keyword) {
      $crons = $crons->where(function ($query) use ($keyword) {
        $query = $query
          ->orWhere("name", "like", "%$keyword%")
          ->orWhere("errors_logs", "like", "%$keyword%");
      });
    }

    if (!$startDate || !$endDate) {
      $interval = DateInterval::createFromDateString("-7 Day");
      $startDate = date_create()->add($interval)->format("Y-m-d");
      $endDate = date_create()->format("Y-m-d");

      $datas = array_merge([
        'keyword' => $keyword,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'checkedType' => $checkedType,
        'cronErrors' => $crons,
        'cronTypes' => $cronTypes,
        // 'b2bCronTypes' => $b2bCronTypes,
        'cronType' => $cronType === "B2B%" ? "B2B" : $cronType
      ], $includesStatus);
  
      return view("logs.cronLogsIndex", $datas);
  
    }



    $crons = $crons->where(function ($query) use ($includesStatus, &$datas) {

      foreach ($includesStatus as $inputKey => $status) {
        if ($status["isToInclude"] === true) {
          $datas[$inputKey] = true;
          $query = $query->orWhere("status", $status["status"]);
        }
      }
    });

    if ($cronType != "all") {
      $crons = $crons->where("name", "like", "$cronType");
    }

    $crons = $crons
      ->where("time", ">=", $startDate)
      ->where("time", "<=", $endDate . " 23:59:59")
      ->get();

    $uncheckedCronsNumber = CronLog::where("name", "like", "b2b%")
      ->whereNotIn("status", [CronLogStatus::SUCCEEDED, CronLogStatus::PRODUCT_IMG_ERRORS, CronLogStatus::PRODUCT_INTEGRITY_ERRORS])
      ->where(function ($query) {
        $query = $query->orWhereNull("checked")
          ->orWhere("checked", 0);
      })->get()->count();

    // $productIntegrityErrorsNumber = CronLog::where("name", "like", "b2b%")
    //   ->where("status", "=", CronLogStatus::PRODUCT_INTEGRITY_ERRORS)
    //   ->where(function ($query) {
    //     $query = $query->orWhereNull("checked")
    //       ->orWhere("checked", 0);
    //   })->get()->count();

    // $imageErrorNumber = CronLog::where("name", "like", "b2b%")
    //   ->where("status", "=", CronLogStatus::PRODUCT_IMG_ERRORS)
    //   ->where(function ($query) {
    //     $query = $query->orWhereNull("checked")
    //       ->orWhere("checked", 0);
    //   })->get()->count();


    $includesStatus = array_filter($includesStatus, function ($status) {
      return $status["isToInclude"];
    });

    $datas = array_merge([
      'keyword' => $keyword,
      'startDate' => $startDate,
      'endDate' => $endDate,
      'checkedType' => $checkedType,
      'cronErrors' => $crons,
      'uncheckedCronsNumber' => $uncheckedCronsNumber,
      'cronTypes' => $cronTypes,
      // 'b2bCronTypes' => $b2bCronTypes,
      'cronType' => $cronType === "B2B%" ? "B2B" : $cronType
    ], $includesStatus);

    return view("logs.cronLogsIndex", $datas);
  }

  public function traceLogsViewer()
  {
    $excludeGaladrim = Input::get('excludeGaladrim');
    $startDate = Input::get('startDate');
    $endDate = Input::get('endDate');
    $keyword = Input::get('keyword') ?: "";
    $exclude = Input::get('exclude') ?: "";
    $startTime = Input::get('startTime');

    if ($startDate !== null && $endDate !== null) {
      $traces = TraceLog::join("users", "users.id", "=", "trace_logs.user_id")
        ->select("trace_logs.*", "users.name")
        ->where("date", ">=", $startDate)
        ->where("date", "<=", $endDate . " 23:59:59");
  
      if ($keyword !== "") {
        $traces = $traces->where(function ($query) use ($keyword) {
          $query->orWhere("log_id", "like", "%$keyword%");
          $query->orWhere("name", "like", "%$keyword%");
          $query->orWhere("response_code", "like", "%$keyword%");
        });
      }
  
      if ($exclude !== "") {
        $traces = $traces->where("log_id", "not like", "%$exclude%");
        $traces = $traces->where("name", "not like", "%$exclude%");
        $traces = $traces->where("response_code", "not like", "%$exclude%");
      }
  
      if ($excludeGaladrim === "on") {
        $traces = $traces->where("users.id", "!=", 28);
      }

      if ($startTime !== null) {
        // Subtract 2 hours from the start time
        $startTimeObj = new \DateTime($startTime);
        $startTimeObj->modify('-2 hours');
        $startTime = $startTimeObj->format('H:i:s');
        $startTime = $startDate . " " . $startTime;
        $traces = $traces->where("date", ">=", $startTime);
      }
  
      $traces = $traces->orderBy("id", "desc")->get();
    } else {
      $traces = [];
    }


    return view("logs.traceIndex", [
      'traces' => $traces,
      'startDate' => $startDate,
      'endDate' => $endDate,
      'keyword' => $keyword,
      'excludeGaladrim' => $excludeGaladrim,
      'exclude' => $exclude,
    ]);
  }

  public function debugLogsViewer()
  {
    $excludeGaladrim = Input::get('excludeGaladrim');
    $startDate = Input::get('startDate');
    $endDate = Input::get('endDate');
    $startTime = Input::get('startTime');
    $keyword = Input::get('keyword') ?: "";
    $exclude = Input::get('exclude') ?: "";

    if ($startDate !== null && $endDate !== null){
      $debugLogs = DebugLog::query()
        ->where("log_date", ">=", $startDate)
        ->where("log_date", "<=", $endDate . " 23:59:59");

      if ($keyword !== "") {
        $keywordArray = explode(" ", $keyword);
        foreach ($keywordArray as $keywordItem) {
          $debugLogs = $debugLogs->where(function ($query) use ($keywordItem) {
            $query->orWhere("log_id", "like", "%$keywordItem%");
            // $query->orWhere("name", "like", "%$keyword%");
          });
        }
      }

      if ($exclude !== "") {
        $excludeArray = explode(" ", $exclude);
        foreach ($excludeArray as $excludeItem) {
          $debugLogs = $debugLogs->where("log_id", "not like", "%$excludeItem%");
        }
        // $debugLogs = $debugLogs->where("name", "not like", "%$exclude%");
      }

      if ($excludeGaladrim === "on") {
        $debugLogs = $debugLogs->where("log_id", "not like", "%galadrim%");
      }

      if ($startTime !== null) {
        // Subtract 2 hours from the start time
        $startTimeObj = new \DateTime($startTime);
        $startTime = $startTimeObj->format('H:i:s');
        $startTime = $startDate . " " . $startTime;
        $debugLogs = $debugLogs->where("log_date", ">=", $startTime);
      }

      $debugLogs = $debugLogs->orderBy("id", "desc")->get();
    } else {
      $debugLogs = [];
    }

    return view("logs.debugLogsIndex", [
      'debugLogs' => $debugLogs,
      'startDate' => $startDate,
      'startTime' => $startTime,
      'endDate' => $endDate,
      'keyword' => $keyword,
      'excludeGaladrim' => $excludeGaladrim,
      'exclude' => $exclude,
    ]);
  }

  public function toggleCronlogCheck()
  {
    try {
      $cronId = Input::get("id");
      $log = CronLog::find($cronId);
      $currentCheckValue = $log->checked;
      if ($currentCheckValue == null || $currentCheckValue == 0) {
        $currentCheckValue = 1;
      } else {
        $currentCheckValue = 0;
      }

      $log->checked = $currentCheckValue;
      $log->save();
    } catch (\Exception $e) {
      return ["error" => $e->getMessage()];
    }
    return ["success" => true, "newCheckedState" => $currentCheckValue];
  }

  public function createInitialStocks()
  {

    function buildInsertQuery($items, $productType)
    {
      $query = "INSERT INTO `warehouse_stocks` (`product_id`, `product_type`, `quantity`, `warehouse_id`) VALUES\n";
      $values = [];
      foreach ($items as $item) {
        if ($productType === ProductType::REFERENCED) {
          $quantity = $item->quantity_available;
        } else {
          $quantity = $item->pieces_available;
        }
        $values[] = "($item->id, '" .  $productType . "', " . $quantity . ", " . 1 . ")";
      }

      return $query . implode(",\n", $values);
    }

    $products = Product::select("products.*")->get();
    DB::insert(buildInsertQuery($products, ProductType::REFERENCED));

    $packItems = PackItem::select("pack_items.*")->get();
    DB::insert(buildInsertQuery($packItems, ProductType::PIECE));
  }

  public function splitPreorder($id, $splitOffset = 150)
  {
    DB::transaction(function () use ($id, $splitOffset) {
      $splitCount = $splitOffset;

      $preorder = Preorder::find($id);
      $newPreorder = $preorder->cloneModel();

      $itemsCount = count($preorder->items);
      for ($index = $splitCount; $index < $itemsCount; $index++) {
        $item = $preorder->items[$index];
        if ($item->orders_ordered_or_prepared_quantity === 0) {
          $item->preorder_id = $newPreorder->id;
          $item->save();
        } else {
          PreorderItem::create([
            "pack_quantity" => $item->remaining_pack_quantity,
            "total_quantity" => $item->remaining_total_quantity,
            "product_unit_price" => $item->product_unit_price,
            "product_reference" => $item->product_reference,
            "arrival_id" => $item->arrival_id,
            "is_referenced" => $item->is_referenced,
            "preorder_id" => $newPreorder->id,
            "is_ordered" => $item->is_ordered,
          ]);
          // $item->preorder_id = $newPreorder->id;
          $item->total_quantity = $item->orders_ordered_or_prepared_quantity;
          $item->pack_quantity = $item->orders_ordered_or_prepared_pack_quantity;
          $item->save();
        }
      }

      echo $newPreorder->reference . " / " . $newPreorder->id;
    });





    // $splitCount = 100;
    // $itemsCount = count($preorder->items);
    // $partsCount = floor($itemsCount / 2);
    // $preorders = [];
    // for ($part = 1; $part <= $partsCount; $part++){
    //   $preorders []= $preorder->cloneModel();
    //   $lastIndex = count($preorders) - 1;
    //   for ($index = $part * 2; $index < ($part + 1) * 2; $index++){
    //     if ($index < $itemsCount){
    //       $item = $preorder->items[$index];
    //       $item->preorder_id = $preorders[$lastIndex]->id;
    //       $item->save();
    //     }
    //   }
    // }

    // dd($preorders);






  }

  public function ticket_457_modification_segmentations()
  {
    DB::transaction(function () {
      // Blackstore => multimarque
      $productsBlackstore = Product::join("products_segmentations", "products.id", "=", "products_segmentations.product_id")
        ->where('segmentation', 'Black store')
        ->get();

      echo "<br>Nombre de produits blackstore : " . count($productsBlackstore);
      echo "<br>Liste :";
      foreach ($productsBlackstore as $product) {
        echo "<br>" . $product->reference . "-" . $product->color_reference;
        $product->addSegmentation("Multi-marque");
        $product->removeSegmentation("Black store");
      }

      // Espoagne Lifestyle => Espagne multimarque
      // Espoagne Sport => Espagne multimarque
      $productsSpanish = Product::join("products_segmentations", "products.id", "=", "products_segmentations.product_id")
        ->orWhere('segmentation', 'Espagne Lifestyle')
        ->orWhere('segmentation', 'Espagne Sport')
        ->get();

      echo "<br><br>";
      echo "<br>************************************************************************";
      echo "<br>Nombre de produits espagne : " . count($productsSpanish);
      echo "<br>Liste :";
      foreach ($productsSpanish as $product) {
        echo "<br>" . $product->reference . "-" . $product->color_reference;
        $product->removeSegmentation('Espagne Lifestyle');
        $product->removeSegmentation('Espagne Sport');
        $product->addSegmentation("Espagne multi-marque");
      }
    });
  }

  public function debugEndPoint()
  {
    $product = Product::find(Input::get("productId"));
    $removalResult = $product->removeSegmentation("Black store");
    $addResult = $product->addSegmentation("Multi-marque");
    if ($removalResult === true) {
      echo "removed<br>";
    } else {
      throw $removalResult;
    }

    if ($addResult === true) {
      echo "added<br>";
    } else {
      throw $addResult;
    }
    return 200;
  }

  public function factoryOrderDoubleValidationFix()
  {
    $changes = DB::select(
      "SELECT
        fop.id as factory_order_product_id,
        foc.*,
        (
          SELECT 
            CONCAT_WS('---', foc2.id, foc2.date_change, foc2.changement)
          FROM factory_orders_changes foc2 
          where 
            ABS(foc2.date_change - foc.date_change) < 10
            AND foc2.factory_order_id = foc.factory_order_id 
            AND foc2.id != foc.id
            AND foc2.id > foc.id
          LIMIT 1
        ) as second_change
      FROM factory_orders_changes foc
      JOIN factory_orders fo on fo.id = foc.factory_order_id 
      JOIN factory_orders_products fop on fop.id = fo.factory_order_product_id 
      WHERE (
          SELECT 
            CONCAT(foc2.date_change, ' : ', foc2.changement)
          FROM factory_orders_changes foc2 
          WHERE 
            ABS(foc2.date_change - foc.date_change) < 10
            AND foc2.factory_order_id = foc.factory_order_id 
            AND foc2.id != foc.id
            AND foc2.id > foc.id
            LIMIT 1
        ) IS NOT NULL
    "
    );
    foreach ($changes as $key => $change) {
      $details_1 = $change->changement;
      $type = explode(" : ", $details_1);
      $type = array_pop($type);
      $type = explode(" ", $type)[0];
      $details_2 = $change->second_change;
      $QUANTITY_BEFORE = 0;
      $QUANTITY_AFTER = 1;
      $quantityToDecrease = 0;
      if (explode(" ", $details_1)[0] === "Création") {
        echo "********* CREATION $type *********<br>";

        $quantityAdded = explode("(", $details_1);
        $quantityAdded = array_pop($quantityAdded);
        $quantityAdded = intval(explode(")", $quantityAdded)[0]);
        $quantities_1 = [0, $quantityAdded];

        $details_2 = explode("/ ", $details_2);
        $quantities_2 = array_map(function ($quantity) {
          return intval($quantity);
        }, explode(" ➡ ", array_pop($details_2)));

        if (
          $quantities_1[$QUANTITY_AFTER] === $quantities_2[$QUANTITY_BEFORE]
          && ($quantities_1[$QUANTITY_AFTER] - $quantities_1[$QUANTITY_BEFORE] === $quantities_2[$QUANTITY_AFTER] - $quantities_2[$QUANTITY_BEFORE])
        ) {
          echo " *** Bug detected *** Double incrementation of " . ($quantities_1[$QUANTITY_AFTER] - $quantities_1[$QUANTITY_BEFORE]) . "<br>";
          $quantityToDecrease = $quantities_1[$QUANTITY_AFTER] - $quantities_1[$QUANTITY_BEFORE];
          echo '<pre>$quantityToDecrease<br />';
          var_dump($quantityToDecrease);
          echo '</pre>';
        } else {
          echo "No bug here...<br>";
          echo '<pre>$change<br />';
          var_dump($change);
          echo '</pre>';
        }

        echo "****************************<br><br><br>";
      } else {
        echo "********* MODIFICATION $type *********<br>";
        $details_1 = explode("/ ", $details_1);
        $quantities_1 = array_map(function ($quantity) {
          return intval($quantity);
        }, explode(" ➡ ", array_pop($details_1)));

        $details_2 = explode("/ ", $details_2);
        $quantities_2 = array_map(function ($quantity) {
          return intval($quantity);
        }, explode(" ➡ ", array_pop($details_2)));

        if (
          $quantities_1[$QUANTITY_AFTER] === $quantities_2[$QUANTITY_BEFORE]
          && ($quantities_1[$QUANTITY_AFTER] - $quantities_1[$QUANTITY_BEFORE] === $quantities_2[$QUANTITY_AFTER] - $quantities_2[$QUANTITY_BEFORE])
        ) {
          echo " *** Bug detected *** Double incrementation of " . ($quantities_1[$QUANTITY_AFTER] - $quantities_1[$QUANTITY_BEFORE]) . "<br>";
          $quantityToDecrease = $quantities_1[$QUANTITY_AFTER] - $quantities_1[$QUANTITY_BEFORE];
          echo '<pre>$quantityToDecrease<br />';
          var_dump($quantityToDecrease);
          echo '</pre>';
        } else {
          echo "No bug here...<br>";
          echo '<pre>$change<br />';
          var_dump($change);
          echo '</pre>';
        }

        echo "****************************<br><br><br>";
      }
      if ($type === "Pack") {
        $factoryOrder = FactoryOrder::find($change->factory_order_id);
        $factoryOrderSize = $factoryOrder->getFactoryOrderSize();
        echo "Quantity before correction : " . $factoryOrderSize->ordered_quantity . "<br>";
        $factoryOrderSize->ordered_quantity -= $quantityToDecrease;
        echo "Quantity after correction : " . $factoryOrderSize->ordered_quantity . "<br>";
        $factoryOrderSize->save();
        $secondChange = FactoryOrderChange::find(explode("---", $change->second_change)[0]);
        echo '<pre>$secondChange->id<br />';
        var_dump($secondChange->id);
        echo '</pre>';
        $secondChange->delete();
      }
    }
  }

  public function buildSegmentationInsertQuery($products, $segmentation)
  {
    $query = "INSERT IGNORE INTO `products_segmentations` (`product_id`, `segmentation`) VALUES\n";
    $values = [];
    foreach ($products as $product) {
      $values[] = "($product->id, '" .  $segmentation . "')";
    }
    return $query . implode(",\n", $values);
  }

  public function addSegmentationsToAllProducts($segmentation)
  {
    $products = Product::all();
    DB::insert($this->buildSegmentationInsertQuery($products, $segmentation));
  }

  public function recomputePaymentReference()
  {
    $last_payment = Payment::where('reference', 'LIKE', 'PAI-%')->orderBy('id', 'desc')->first();
    $last_reference = $last_payment->reference;
    $paymentsGroupedByReference = Payment::where("created_at", ">=", "2023-07-02 18:32:40")
      ->having(DB::raw("count(*)"), ">", 1)
      ->groupBy("payments.reference")
      ->get();

    foreach ($paymentsGroupedByReference as $payment) {
      $paymentsToModify = Payment::where("reference", $payment->reference)->get();
      foreach ($paymentsToModify as $key => $paymentToModify) {
        if ($key > 0) {
          $paymentToModify->reference = ++$last_reference;
          $paymentToModify->save();
        }
      }
    }
  }

  public function addHoursToOperationsQuantityChanges($productId, $hours, $startDate)
  {
    $changes = ProductQuantityChange::where("product_id", $productId)
      ->whereNull("user_id")
      ->where("created_at", ">=", $startDate)
      ->get();
    DebugLog::logJson("Add hours to operations quantity changes", $changes->toArray());

    $report = ["historique avant correction" => $changes->toArray()];

    foreach ($changes as $change) {
      $change->created_at = $change->created_at->addHours($hours);
      $change->save();
    }

    return $report;
  }

  public function propageQuantityInChanges($fromChange, $quantity)
  {
    function adjustMovement($movement, $quantity)
    {
      $movement->global_quantity_before = $movement->global_quantity_before + $quantity;
      $movement->quantity_before = $movement->quantity_before + $quantity;
      $movement->global_quantity_after = $movement->global_quantity_after + $quantity;
      $movement->quantity_after = $movement->quantity_after + $quantity;
      $movement->save();
    }
    $followingMovements = ProductQuantityChange::where("product_id", $fromChange->product_id)
      ->where("created_at", ">", $fromChange->created_at)
      ->get();

    foreach ($followingMovements as $followingMovement) {
      adjustMovement($followingMovement, $quantity);
    };
  }

  public function realignQuantitiesForm()
  {
    $productId = Input::get("productId");
    $correctionType = Input::get("correctionType");
    $hours = Input::get("hours");
    $startDate = Input::get("startDate");
    $nbCorrections = intval(Input::get("nbCorrections", 1));
    $tracker = ProgressTracker::getTracker();
    $tracker->setTargetCount($nbCorrections);
    $report = [];
    if ($productId === null) {
      return view("logs.realignQuantitiesForm");
    } else {
      if ($correctionType === "correctFirstChange") {
        for ($i = 0; $i < $nbCorrections; $i++) {
          $tracker->addCount();
          $corrector = new ProductQuantityCorrector($productId);
          $corrector->correctFirstChange();
          $currentReport = $corrector->getReport();
          $report = array_merge($report, $currentReport);
          if (count($currentReport) > 0 && $currentReport[count($currentReport) - 1]['message'] === "Aucun mouvement en erreur trouvé") {
            break;
          }
        }
      } else if ($correctionType === "addHours") {
        $report = $this->addHoursToOperationsQuantityChanges($productId, $hours, $startDate);
      }
  
      return view("logs.realignQuantitiesForm", [
        "productId" => $productId,
        "correctionType" => $correctionType,
        "report" => $report,
        "hours" => $hours,
        "startDate" => $startDate,
        "nbCorrections" => $nbCorrections,
      ]);
    }
  }

  /**
   * Permet de corriger les mouvements de quantités et de réaligner les stocks  
   * Les options permettent de cibler le mouvement à partir duquel il faut corriger  
   * A noter : il faut cilber le dernier mouvement à ne pas avoir d'erreur  
   * !! Attention : si aucune option n'est passée, tous les mouvements en erreurs seront corrigés
   * 
   * @param mixed $productId
   * @param $options =
   * [  
   * - "movementType" => ... ,
   * - "userName" => ... ,
   * - "dateTime" => dd/mm/yyyy hh:mm:ss  
   * ]
   * 
   * @return [type]
   */
  public function realignQuantities($productId, $options)
  {    
    $report = [];
    DB::transaction(function () use ($productId, $options, &$report) {
      $movementType = isset($options['movementType']) ? $options['movementType'] : null;
      $userName = isset($options['userName']) ? $options['userName'] : null;
      $dateTime = isset($options['dateTime']) ? $options['dateTime'] : null;
      $product = Product::find($productId);
      if (!$product) {
        return ["error" => "Produit introuvable"];
      }
      $problematicMovementsList = DB::select(
        "SELECT
          pqc.product_id,
          pqc.id as current_id,
          nextRow.id as next_id,
          pqc.user_id,
          pqc.created_at, 
          pqc.global_quantity_before as current_before,
          pqc.global_quantity_after as current_after, 
          nextRow.global_quantity_before as next_before, 
          nextRow.global_quantity_after as next_after, 
          pqc.global_quantity_after - nextRow.global_quantity_before as diff
        from product_quantity_changes pqc 
        join product_quantity_changes nextRow on nextRow.id = (
          SELECT MIN(id)
          from product_quantity_changes pqc2 
          where pqc2.id > pqc.id 
          AND pqc.product_id = pqc2.product_id
        )
        where pqc.global_quantity_after - nextRow.global_quantity_before != 0
        AND pqc.created_at > '2023-06-26'
        AND pqc.product_id = '" . intval($productId) . "'"
      );
      $report []= "<br><br>Produit id $productId ********************************<br>";
      if (count($problematicMovementsList) > 0) {
        $report []= "erreur détectées<br>";
        foreach ($problematicMovementsList as $movement) {
          if ($movement->current_after != $movement->next_before) {
            $currentChange = ProductQuantityChange::where("id", $movement->current_id)->first();
            $errorChange = ProductQuantityChange::where("id", $movement->next_id)->first();

            $movementValidation = ($movementType !== null ? $movementType === $currentChange->username : true)
              && ($userName !== null ? $userName === $currentChange->username : true)
              && ($dateTime !== null ? $dateTime === $currentChange->date : true);

            $currentDiff = $movement->current_after - $movement->current_before;
            if (!$movementValidation) {
              $report []= "Mouvement en erreur / Conditions non remplies ********************************";
              $report []= '$movementType';
              $report []= var_export($movementType, true);

              $report []= '$userName';
              $report []= var_export($userName, true); 

              $report []= '$dateTime';
              $report []= var_export($dateTime, true);

              $report []= '$currentChange';
              $report []= var_export($currentChange, true);

              DebugLog::logJson("realignQuantities - erreur de type de mouvement", [
                "movementType" => $movementType,
                "userName" => $userName,
                "dateTime" => $dateTime,
                "currentChange" => $currentChange,
                "errorChange" => $errorChange
              ]);
            } else {
              $report []= "Mouvement en erreur / Conditions remplies********************************";

              $followingMovements = ProductQuantityChange::where("product_id", $movement->product_id)
                ->where("id", ">", $movement->current_id)
                ->get();

              DebugLog::logJson("realignQuantities - mouvement corrigé", [
                "movementType" => $movementType,
                "userName" => $userName,
                "dateTime" => $dateTime,
                "correctedQuantity" => $currentDiff,
                "currentChange" => $currentChange,
                "errorChange" => $errorChange,
              ]);

              foreach ($followingMovements as $followingMovement) {
                $followingMovement->global_quantity_after = $followingMovement->global_quantity_after + $currentDiff;
                $followingMovement->quantity_after = $followingMovement->quantity_after + $currentDiff;
                $followingMovement->global_quantity_before = $followingMovement->global_quantity_before + $currentDiff;
                $followingMovement->quantity_before = $followingMovement->quantity_before + $currentDiff;
                $followingMovement->save();
              };
            }
          }
        }
        $lastMovement = ProductQuantityChange::where("product_id", $productId)->orderBy("id", "DESC")->first();
        $mainStock = $product->getMainWarehouseStock();

        $stockBeforeCorrection = $mainStock->quantity;
        $stockAfterCorrection = $lastMovement->quantity_after;

        $report [] = '$stockBeforeCorrection';
        $report []= var_export($stockBeforeCorrection, true);

        $report [] = '$stockAfterCorrection';
        $report []= var_export($stockAfterCorrection, true);

        $mainStock->quantity = $lastMovement->quantity_after;
        $mainStock->save();
        $product->updateAvailableQuantity();

        DebugLog::logJson("realignQuantities - stock dépôt principal modifié", [
          "movementType" => $movementType,
          "userName" => $userName,
          "dateTime" => $dateTime,
          "correctedQuantity" => $currentDiff,
          "stockBeforeCorrection" => $stockBeforeCorrection,
          "stockAfterCorrection" => $stockAfterCorrection,
        ]);
      }
    });

    return $report;
  }

  public function detectQuantityProblem_Ticket590()
  {
    $movementsSum = DB::select(
      "SELECT 
        pqc.product_id, 
        sum(pqc.global_quantity_after - pqc.global_quantity_before) as total, 
        firstMovement.quantity_before as firstQuantity,
        p.quantity_available
      from product_quantity_changes pqc 
      join product_quantity_changes firstMovement on firstMovement.id = (select Min(id) FROM products_quantity_changes pqc2 where pqc2.product_id = pqc.product_id)
      join products p on p.id = pqc.product_id
      where pqc.product_id is not null
      group by pqc.product_id;"
    );
    dd(array_slice($movementsSum, 0, 100));

    // foreach ($movementsSum as $movementSum) {
    //   $productQuantity = DB::select("SELECT quantity_available FROM products WHERE id = ". $movementSum->product_id. " LIMIT 1")[0]->quantity_available;
    //   $firstMovement = ProductQuantityChange::where("product_id", $movementSum->product_id)->orderBy("id", "ASC")->first();

    //   if ($movementSum->total != $productQuantity - $firstMovement->global_quantity_before){
    //     echo "<br><br>****************************************************************<br>";
    //     echo '<pre>$movementSum<br />'; var_dump($movementSum); echo '</pre>';
    //   }
    // }
  }

  public function bugFixFranchiseMovements_Ticket590()
  {
    // Récupération des changements avec des erreurs de stock
    $productQuantityChanges = DB::select(
      "SELECT
        pqc.product_id, 
        pqc.type, 
        pqc.created_at, 
        pqc.quantity_after - p.quantity_available as diff, 
        pqc.quantity_before, 
        pqc.quantity_after, 
        p.quantity_available,
        p.updated_at
      from products p
      join product_quantity_changes pqc on pqc.product_id = p.id
      where pqc.id in (select max(pqc2.id) from product_quantity_changes pqc2 group by pqc2.product_id)
      and p.quantity_available != pqc.quantity_after
      and pqc.created_at > '2021-03-10'
      -- and p.id not in (
      --   select pqc3.product_id from products p
      --   join product_quantity_changes pqc3 on pqc3.product_id = p.id
      --   where pqc3.id in (select min(pqc4.id) from product_quantity_changes pqc4 group by pqc4.product_id)
      --   and pqc3.created_at < '2021-10-03'
      -- )
      "
    );

    $productsIds = array_map(function ($item) {
      return $item->product_id;
    }, $productQuantityChanges);

    echo count($productsIds) . "lignes<br>";
    if (count($productsIds) != count(array_unique($productsIds))) {
      echo "Some products are found more than once <br>";
    } else {
      echo "All products are unique <br>";
    }
    // Récuperation des changements avec des erreurs et impliquant des franchises
    $changesFromFranchises = DB::select(
      "SELECT
    rs.id as store_id,
    rs.isFranchise,
    pqc.*,
    GROUP_CONCAT(o.id) as order_ids
    from product_quantity_changes pqc
    join orders o on o.id = pqc.order_id
    join retail_stores rs on rs.client_id = o.client_id
    where pqc.id in (select max(pqc2.id) from product_quantity_changes pqc2 group by pqc2.product_id)
    AND pqc.product_id in (" . implode(",", $productsIds) . ")
    And pqc.type = 'order'
    and isFranchise = 1
    group by pqc.id
    "
    );

    $errorsSinceBugIntroduction = [];
    $errorsBeforeBugIntroduction = [];
    foreach ($productsIds as $productId) {
      // Récupération des changements 
      $problematicMovementsList = DB::select(
        "SELECT
          pqc.id,
          pqc.user_id,
          pqc.created_at, 
          pqc.global_quantity_before as current_before,
          pqc.global_quantity_after as current_after, 
          nextRow.global_quantity_before as next_before, 
          nextRow.global_quantity_after as next_after, 
          pqc.global_quantity_after - nextRow.global_quantity_before as diff
        from product_quantity_changes pqc 
        join product_quantity_changes nextRow on nextRow.id = (
          SELECT MIN(id)
          from product_quantity_changes pqc2 
          where pqc2.id > pqc.id 
          AND pqc.product_id = pqc2.product_id
        )
        where pqc.global_quantity_after - nextRow.global_quantity_before != 0
        -- AND pqc.created_at > '2023-06-26'
        AND pqc.product_id = " . $productId
      );
      if (count($problematicMovementsList) > 0) {
        $errorsSinceBugIntroduction[$productId] = $problematicMovementsList;
      } else {
        $errorsBeforeBugIntroduction[$productId] = true;
      }
    }
    echo '<br>****************************************************************<br>';
    echo count($errorsSinceBugIntroduction) . " lignes avec des erreurs depuis introduction du bug<br>";
    echo count($errorsBeforeBugIntroduction) . " lignes avec des erreurs uniquement avant la date d'introduction du bug<br>";
    echo '****************************************************************<br>';


    dd($errorsBeforeBugIntroduction);
    return;







    // $movementDetailsPerProducts = analyse par produit avec notamment :
    // - franchisesMovements => [ total, quantities_before, quantities_after ]
    // - difference => difference entre le dernier quantity_change et le stock actuel
    // - diff_franchise_comparison => boolean - est-ce que la difference entre le dernier quantity_change et le stock actuel est égale au total des mouvements franchises ?
    $movementDetailsPerProducts = [];
    $bugIntroductionDate = "2023-06-27 17:17:00";
    foreach ($changesFromFranchises as $changeFromFranchise) {
      $product = Product::find($changeFromFranchise->product_id);
      $movements = $product->getWarehouseMovements("all", $bugIntroductionDate, date("Y-m-d H:i:s"))
        ->filter(function ($movement) {
          return strstr($movement->username, "franchise") !== false;
        });

      $difference = 0;

      $totalMovements = $movements->reduce(function ($summary, $movement) use (&$difference, $changeFromFranchise, $product) {
        if ($changeFromFranchise->created_at === $movement->created_at->format("Y-m-d H:i:s")) {
          $difference = $movement->balance_computed - $product->total_stock;
        }
        $summary["total"] = $summary["total"] + $movement->quantity_after - $movement->quantity_before;
        $summary["quantities_before"][] = $movement->quantity_before;
        $summary["quantities_after"][] = $movement->quantity_after;
        return $summary;
      }, ["total" => 0, "quantities_before" => [], "quantities_after" => []]);

      if (isset($movementDetailsPerProducts[$product->id])) {
        // $movementDetailsPerProducts[$product->id] = [];
        echo "Product alread in list : " . $product->id;
        continue;
      }

      $movementDetailsPerProducts[$product->id] = [
        "franchisesMovements" => $totalMovements,
        "difference" => $difference,
        "diff_franchise_comparison" => $difference == $totalMovements["total"],
        "movement" => $changeFromFranchise,
        "franchise_created_at" => $changeFromFranchise->created_at,
      ];
    }

    // Cherche les produits dont la différence entre le dernier quantity_change et le stock actuel n'est pas égale au total des mouvements franchises.
    // Le résultat est 0 produit => le problème est bien identifié sur ces produits. 
    $productsWithNotMatchingDifferences = array_filter($movementDetailsPerProducts, function ($product) {
      return  !$product["diff_franchise_comparison"];
    });

    echo count($productsWithNotMatchingDifferences) . " products with not matching differences <br>";
    dd($movementDetailsPerProducts);
  }

  public function quantitySoldAnalysis_Ticket590()
  {
    $products = Product::all();
    $productsWithErrors = [];
    $productsWithoutErrors = [];
    foreach ($products as $product) {
      if ($product->quantity_sold != intval($product->quantity_sold_from_orders)) {
        $productsWithErrors[] = [
          'id' => $product->id,
          'reference' => $product->reference,
          'quantity_sold' => $product->quantity_sold,
          'quantity_sold_from_orders' => intval($product->quantity_sold_from_orders),
          'difference' => $product->quantity_sold - $product->quantity_sold_from_orders
        ];
      } else {
        $productsWithoutErrors[] = [
          'id' => $product->id,
          'reference' => $product->reference,
          'quantity_sold' => $product->quantity_sold,
          'quantity_sold_from_orders' => intval($product->quantity_sold_from_orders)
        ];
      }
    }
    echo "errors : " . count($productsWithErrors);
    DebugLog::logJson("Quantity sold errors", $productsWithErrors);
    dd($productsWithoutErrors);
  }

  public function fixFirstOrderReferenceDoublon()
  {
    $doublonsReferences = Order::select("reference")
      ->where('created_at', '>=', '2023-01-01')
      ->where("status", "!=", OrderStatus::CANCELLED)
      ->groupBy("reference")
      ->havingRaw("count(*) > 1")
      ->get();

    $doublonsReferences = $doublonsReferences->map(function ($doublon) {
      return $doublon->reference;
    });
    $orders = Order::where("reference", $doublonsReferences[0])->get();
    $orders[1]->reference = Order::getNewReference();
    $orders[1]->save();
    echo "Other order id is " . $orders[0]->id . " with reference " . $orders[0]->reference . "<br>";
    echo "Order with id " . $orders[1]->id . " has been updated with new reference " . $orders[1]->reference . "<br>";
    return $doublonsReferences;
  }

  public function addExtraClients()
  {
    // Ajoute les clients au commercial
    //Les clients sont les clients qui ont : 
    // INTERSPORT
    // BLACKSTORE
    // SPORT 2000
    //  dans leur nom
    $clients = Client::where("Name", "LIKE", "%INTERSPORT%")
      ->orWhere("Name", "LIKE", "%BLACKSTORE%")
      ->orWhere("Name", "LIKE", "%SPORT 2000%")
      ->get();
    // Mat Lejot;
    $agent = User::find(82);
    $existingExtraClientsIds = $agent->extraClients->map(function ($client) {
      return $client->id;
    });
    foreach ($clients as $client) {
      if (!in_array($client->id, $existingExtraClientsIds->toArray())) {
        ClientAgentAssociation::associateClientToAgent($client, $agent);
      }
    }
  }


  /**
   * Supprime tous les process DB dans l'état "SLEEP"
   */
  public function killSleepDBProcesses()
  {
    $processes = DB::select("SHOW FULL PROCESSLIST");
    $count = 0;
    foreach ($processes as $process) {
      if ($process->Command === "Sleep") {
        DB::select("KILL $process->Id");
        echo "Process id $process->Id killed<br>";
        $count++;
      }
    }
    echo "*************  END  *******************<br><br>";
    echo "$count processes killed";
  }

  /**
   * Ne traite pour le moment que les produits non référencés
   * @param mixed $orderId
   * @param bool $proceedAction
   * 
   * @return [type]
   */
  public function deleteDoublonProducts($orderId, $proceedAction = false, $proceedOnPrepared = false)
  {
    $items = UnreferencedOrderItem::where("order_id", $orderId)->get();
    $itemsByReference = [];
    foreach ($items as $item) {
      $completeReference = $item->product_reference . "-" . $item->designation;
      if (!isset($itemsByReference[$completeReference])) {
        $itemsByReference[$completeReference] = [];
      }
      $itemsByReference[$completeReference][] = $item;
    }
    $logs = [];
    foreach ($itemsByReference as $reference => $items) {
      $log = "";
      if (count($items) > 1) {
        $completeReference = $item->product_reference . "-" . $item->designation;
        $quantitiesAreEqual = true;
        $quantity = $items[0]->total_quantity;
        foreach ($items as $item) {
          if ($item->total_quantity != $quantity) {
            $quantitiesAreEqual = false;
            break;
          }
        }
        $firstItemId = $items[0]->id;
        $itemsModifiedCount = 0;
        if ($quantitiesAreEqual === true) {
          $itemsPrepared = 0;
          foreach ($items as $item) {
            if ($item->id !== $firstItemId) {
              $item->order_id = null;
              $item->composition = $orderId;
              if ($proceedAction === true && ($item->prepared_quantity == 0 || $item->prepared_quantity === null)) {
                $itemsModifiedCount++;
                $item->save();
              } else if ($item->prepared_quantity > 0) {
                $itemsPrepared++;
                if ($proceedOnPrepared === true) {
                  $itemsModifiedCount++;
                  $item->save();
                }
              }
            }
          }
          $log .= count($items) . " x " . $completeReference . " : " . $itemsModifiedCount . " items modified" . ($itemsPrepared > 0 ? " / $itemsPrepared already prepared" : "");
        } else {
          $log .=  "**** **** **** Quantities NOT OK";
        }
        echo  "$log<br>";
        $logs[] = $log;
      }
    }
    DebugLog::logJson("deleteDoublonProducts", $logs);
  }

  public function realignQuantitiesGet($productId)
  {
    $movementType = Input::get("movementType");
    $userName = Input::get("userName");
    $dateTime = Input::get("dateTime");
    $options = [];
    if ($movementType !== null) {
      $options["movementType"] = $movementType;
    }
    if ($userName !== null) {
      $options["userName"] = $userName;
    }
    if ($dateTime !== null) {
      $options["dateTime"] = $dateTime;
    }
    $this->realignQuantities($productId, $options);
  }

  public function ticket_611_analyse_precommandes()
  {
    $product = Product::getByReference("2340014-GY2");
    dd([
      "remainsToDeliver" => $product->remainsToDeliver,
      "factoryOrderedQuantity" => $product->factory_ordered_quantity,
    ]);
  }

  public function preorderAmountUpdateDebug()
  {
    $bornes = [];
    $borneMax = 20;
    $intervalMinutes = 5;
    for ($i = 0; $i < $borneMax; $i++) {
      $bornes[] = Carbon::now()->subMinutes($i * $intervalMinutes)->format("Y-m-d H:i:s");
    }
    $preordersBornes = [];
    for ($i = 1; $i < $borneMax; $i++) {
      $products = Product::join("product_quantity_changes", "product_quantity_changes.product_id", "=", "products.id")
        ->whereBetween('products.updated_at', [$bornes[$i], $bornes[$i - 1]])
        ->whereBetween('product_quantity_changes.updated_at', [$bornes[$i], $bornes[$i - 1]])
        ->groupBy("products.id")
        ->select("products.*")
        ->get();
      $preordersToUpdate = [];
      $products->each(function ($product) use (&$preordersToUpdate) {
        $preordersToUpdate = array_merge($preordersToUpdate, array_keys($product->getPreorderItemsWithRemainingQuantities()));
      });
      $preordersBornes[] = [
        $bornes[$i],
        $bornes[$i - 1],
        count(array_unique($preordersToUpdate)),
        isset($preordersToUpdate[0]) ? $preordersToUpdate[0] : "-",
      ];
    }
    // dd($preordersBornes);
    $total = 0;
    foreach ($preordersBornes as $bornes) {
      // echo "************************************************<br>";
      $total += $bornes[2];
      echo $bornes[0] . " - " . $bornes[1] . " : " . $bornes[2] . "  dont preorder_id : " . $bornes[3] . "<br>";
    }
    dd($total);
  }

  // Permet de recalculer les montants dispos des précommandes par lot de 100
  // La liste des preorder_id est à placer dans la table app_global_settings
  public function preorderAmountUpdateDebug_ticket_612()
  {
    $preordersToUpdate = json_decode(AppGlobalSetting::getSetting(AppGlobalSettingsNames::PREORDERS_FOR_AVAILABLE_AMOUNT_UPDATE));
    echo '<pre>count($preordersToUpdate)<br />';
    var_dump(count($preordersToUpdate));
    echo '</pre>';
    $toUpdateNow = "(" . implode(",", array_splice($preordersToUpdate, 0, 100)) . ")";
    echo '<pre>$toUpdateNow<br />';
    var_dump($toUpdateNow);
    echo '</pre>';
    echo '<pre>count($preordersToUpdate)<br />';
    var_dump(count($preordersToUpdate));
    echo '</pre>';
    $updateAmountsResult = DB::update(
      "UPDATE preorders_available_amount paa
      SET paa.available_amount = (
      SELECT SUM(items.dispo) FROM
      (
          (SELECT
          po.id as preorder_id,
          po.reference,
          pi2.product_reference,
          (
          CASE
              WHEN 
              IF( -- Ce premier if vérifie que total_quantity - ordered_quantity > 0, en renvoie 0 sinon
                  -- Si il existe des commandes pour le preorderItem, alors la quantité commandée à prendre en compte sera : 
                  --   - Soit la quantité préparée si la commandes est déjà facturée
                  --   - Sinon la quantité commandée
                  pi2.total_quantity - COALESCE(GROUP_CONCAT(IF(o.status LIKE 'PREPARED%', oi.prepared_quantity, oi.total_quantity)), 0) > 0, 
                  pi2.total_quantity - COALESCE(GROUP_CONCAT(IF(o.status LIKE 'PREPARED%', oi.prepared_quantity, oi.total_quantity)), 0), 
                  0
              ) < IF( -- Ce deuxième if permet d eviter les bugs liés aux produit avec du stock négatif
                  p.quantity_available > 0, 
                  p.quantity_available, 
                  0
              ) 
              THEN 
              IF(
                  pi2.total_quantity - COALESCE(GROUP_CONCAT(IF(o.status LIKE 'PREPARED%', oi.prepared_quantity, oi.total_quantity)), 0) > 0, 
                  pi2.total_quantity - COALESCE(GROUP_CONCAT(IF(o.status LIKE 'PREPARED%', oi.prepared_quantity, oi.total_quantity)), 0), 
                  0
              )
              ELSE IF( 
              p.quantity_available > 0, 
              p.quantity_available, 
              0
              )
          END
          ) * pi2.product_unit_price as dispo
          FROM preorders po
          JOIN preorder_items pi2 ON pi2.preorder_id = po.id
          LEFT JOIN orders o ON o.preorder_id = pi2.preorder_id
          LEFT JOIN order_items oi ON oi.order_id = o.id
          AND oi.product_reference = pi2.product_reference
          LEFT JOIN products p ON CONCAT(p.reference, '-', p.color_reference) = pi2.product_reference
          WHERE po.status = 'ACTIVE'
          AND pi2.deleted_at IS NULL
          AND po.id IN $toUpdateNow
          GROUP by pi2.id, po.id
      )
      UNION (
      SELECT			
          po.id as preorder_id,
          po.reference,
          CAST(pibp.product_reference AS CHAR),
          (
          CASE
              WHEN 
              IF(-- Ce premier if vérifie que p	_quantity - ordered_quantity > 0, en renvoie 0 sinon
                  -- Si il existe des commandes pour le preorderItem, alors la quantité commandée à prendre en compte sera : 
                  --   - Soit la quantité préparée si la commandes est déjà facturée
                  --   - Sinon la quantité commandée
                  pibp.pieces_quantity - COALESCE(GROUP_CONCAT(IF(o.status LIKE 'PREPARED%', oibp.prepared_quantity, oibp.pieces_quantity)), 0) > 0, 
                  pibp.pieces_quantity - COALESCE(GROUP_CONCAT(IF(o.status LIKE 'PREPARED%', oibp.prepared_quantity, oibp.pieces_quantity)), 0), 
                  0
              ) < IF( 
                  pki.pieces_available > 0, 
                  pki.pieces_available, 
                  0
              ) 
              THEN 
              IF(
                  pibp.pieces_quantity - COALESCE(GROUP_CONCAT(IF(o.status LIKE 'PREPARED%', oibp.prepared_quantity, oibp.pieces_quantity)), 0) > 0, 
                  pibp.pieces_quantity - COALESCE(GROUP_CONCAT(IF(o.status LIKE 'PREPARED%', oibp.prepared_quantity, oibp.pieces_quantity)), 0), 
                  0
              )
              ELSE IF( 
                  pki.pieces_available > 0, 
                  pki.pieces_available, 
                  0
              )
              END
          ) * pibp.product_unit_price as dispo
          FROM preorders po
          JOIN preorder_items_by_pieces pibp ON pibp.preorder_id = po.id
          LEFT JOIN orders o ON o.preorder_id = pibp.preorder_id
          LEFT JOIN order_items_by_pieces oibp ON oibp.order_id = o.id
          LEFT JOIN pack_items pki ON pki.id = pibp.pack_item_id
          WHERE po.status = 'ACTIVE'
          AND po.id IN $toUpdateNow
          GROUP BY po.id, pki.id
      )
      ) items
      WHERE items.preorder_id = paa.preorder_id
      AND paa.preorder_id IN $toUpdateNow
      GROUP BY items.preorder_id
      ) 
      WHERE paa.preorder_id IN $toUpdateNow"
    );

    echo '<pre>$updateAmountsResult<br />';
    var_dump($updateAmountsResult);
    echo '</pre>';
    AppGlobalSetting::setSetting(AppGlobalSettingsNames::PREORDERS_FOR_AVAILABLE_AMOUNT_UPDATE, json_encode($preordersToUpdate));
    echo "UPDATE DONE";
  }

  public function getUserHistoryFromChanges()
  {
    $changesTables = ["arrival_changes", "check_changes", "client_changes", "customer_data_changes", "discount_changes", "dispute_changes", "factory_orders_changes", "operations_products_quantity_changes", "operations_store_product_quantity_changes", "order_changes", "order_status_changes", "payment_changes", "pdv_changes", "pieces_locations_changes", "preorders_changes", "product_changes", "product_price_changes", "product_quantity_changes", "products_locations_changes", "retail_worked_hours_changes", "warehouse_stock_changes", "warehouse_transfer_changes", "credit_changes"];
    $logs = [];
    $data = [];
    foreach ($changesTables as $table) {
      try {
        $data = array_merge($data, DB::select("SELECT * FROM  $table WHERE user_id = 135"));
      } catch (\Exception $e) {
        $logs[] = [
          'table' => $table,
          'error' => $e->getMessage()
        ];
        //throw $th;
      }
    }
    $excel = Excel::create('temp', function (LaravelExcelWriter $excel) use ($data, $logs) {
      $detailsError = [];
      $excel->sheet('History', function (LaravelExcelWorksheet $sheet) use ($data, &$detailsError) {
        foreach ($data as $row => $details) {
          if (property_exists($details, "date_change") && property_exists($details, "changement")) {
            $sheet->setCellValueByColumnAndRow(0, $row, $details->date_change);
            $sheet->setCellValueByColumnAndRow(1, $row, $details->changement);
          } else if (property_exists($details, "created_at") && property_exists($details, "type")) {
            $sheet->setCellValueByColumnAndRow(0, $row, $details->created_at);
            $sheet->setCellValueByColumnAndRow(1, $row, "Mouvement stock boutique : " . $details->type);
          } else {
            $detailsError[] = json_encode($details, JSON_PRETTY_PRINT);
          }
        }
      });
      $excel->sheet('Errors', function (LaravelExcelWorksheet $sheet) use ($logs) {
        foreach ($logs as $row => $log) {
          $sheet->setCellValueByColumnAndRow(0, $row, $log['table']);
          $sheet->setCellValueByColumnAndRow(1, $row, $log['error']);
        }
      });
      $excel->sheet('Details Errors', function (LaravelExcelWorksheet $sheet) use ($detailsError) {
        foreach ($detailsError as $row => $error) {
          $sheet->setCellValueByColumnAndRow(0, $row, $error);
        }
      });
    });
    $filename = 'history';

    $excel
      ->setFilename($filename)
      ->store('xlsx', storage_path('temp/exports'));

    return Response::download(storage_path('temp/exports/' . $filename . '.xlsx'))->deleteFileAfterSend(true);
  }

  public static function killTrackedProcess($trackerId){
    $tracker = ProgressTracker::find($trackerId);
    if (!$tracker) return;
    $tracker->killTrackedProcess();
  }

  public function getLaravelVersion(){
    return app()->version();
  }

  public function checkB2CRestockingStockImpacts()
  {
    // ── 1. Récupération des logs ──────────────────────────────────────────────
    $logs = DebugLog::where('log_id', 'LIKE', '%API request - B2C restocking order%')
      ->orderBy('log_date', 'desc')
      ->get();

    // ── 2. Filtrage : uniquement les commandes avec auto_launch = true ────────
    $autoLaunchLogs = $logs->filter(function ($log) {
      $data = json_decode($log->log_content, true);
      return isset($data['request']['body']['auto_launch'])
        && $data['request']['body']['auto_launch'] === true;
    });

    // ── 3. Analyse de chaque commande ─────────────────────────────────────────
    $ordersWithMissingImpacts = [];
    $ordersOk                 = [];

    foreach ($autoLaunchLogs as $log) {
      $data      = json_decode($log->log_content, true);
      $orderId   = isset($data['response']['body']['order_id']       ) ? $data['response']['body']['order_id'] : null;
      $orderRef  = isset($data['response']['body']['order_reference']) ? $data['response']['body']['order_reference'] : null;
      $status    = isset($data['response']['body']['status']         ) ? $data['response']['body']['status'] : null;
      $requestedItems = isset($data['request']['body']['items']    )    ? $data['request']['body']['items'] : [];

      if (!$orderId) {
        continue;
      }

      $order = Order::find($orderId);
      if (!$order) {
        $ordersWithMissingImpacts[] = [
          'log_date'             => $log->log_date,
          'order_id'             => $orderId,
          'order_reference'      => $orderRef,
          'status'               => $status,
          'erreur'               => 'Commande introuvable en base',
          'items_without_impact' => [],
        ];
        continue;
      }

      $missingItems = [];

      foreach ($order->items as $item) {
        $impactCount = ProductQuantityChange::where('order_id', $orderId)
          ->where('product_id', $item->product_id)
          ->where('type', ProductQuantityChangeType::ORDER)
          ->count();

        if ($impactCount === 0) {
          $missingItems[] = [
            'product_reference' => $item->product_reference,
            'product_id'        => $item->product_id,
            'pack_quantity'     => $item->pack_quantity,
            'total_quantity'    => $item->total_quantity,
            'impacts_trouves'   => 0,
          ];
        }
      }

      $entry = [
        'log_date'        => $log->log_date,
        'order_id'        => $orderId,
        'order_reference' => $orderRef,
        'status'          => $status,
        'nb_items'        => $order->items->count(),
        'items_ko'        => $missingItems,
      ];

      if (empty($missingItems)) {
        $ordersOk[] = $entry;
      } else {
        $ordersWithMissingImpacts[] = $entry;
      }
    }

    // ── 4. Rapport final ──────────────────────────────────────────────────────
    $rapport = [
      'total_logs_trouves'          => $logs->count(),
      'total_commandes_auto_launch' => $autoLaunchLogs->count(),
      'commandes_ok'                => $ordersOk,
      'commandes_avec_impacts_manquants' => $ordersWithMissingImpacts,
    ];

    dd($rapport);
  }

    
  /**
   * Ticket#611
   */
  public function factoryOrderBddMigration($direction, $targetStep)
  {   
    $migration = new FactoryOrderMigration();
    try {
      DB::beginTransaction();
      $migration->factoryOrderBddMigration($direction, $targetStep);
      DB::commit();
    } catch (\Exception $e) {
      DB::rollback();
      throw $e;
    }
  }


  function factoryOrderBddMigrationView()
  {
    return view('debug.factory-order-migrations', [
      'currentStep' => AppGlobalSetting::getSetting(AppGlobalSettingsNames::FACTORY_ORDER_CURRENT_MIGRATION_STEP)
    ]);
  }

  /**
   * Réincrémente manuellement les stocks des produits d'une commande annulée
   * dont la réincrémentation automatique n'a pas fonctionné.
   */
  public function restoreOrderStock($orderId)
  {
    $order = Order::find($orderId);

    if (!$order) {
      return response()->json(['error' => "Commande #$orderId introuvable."], 404);
    }

    if ($order->status !== 'CANCELLED') {
      return response()->json(['error' => "La commande #$orderId n'est pas annulée (statut : {$order->status}). Opération annulée par sécurité."], 422);
    }

    $orderRepository = app(OrderRepository::class);

    DB::beginTransaction();
    try {
      $orderRepository->adjustStock($order, 'order-cancelled');
      DB::commit();
    } catch (\Exception $e) {
      DB::rollBack();
      return response()->json(['error' => 'Erreur lors de la réincrémentation : ' . $e->getMessage()], 500);
    }

    $itemsCount = $order->items->count() + $order->itemsByPieces->count();
    return response()->json([
      'success' => true,
      'message' => "Stocks réincrémentés avec succès pour la commande #$orderId ($itemsCount ligne(s) traitée(s)).",
    ]);
  }

  // TEMPORARY — compare FactoryOrderDataTable SQL aggregates vs Eloquent accessors
  // Route : GET /debug/compare-factory-order-datatable
  // Remove once validation is complete.
    public function compareFactoryOrderDataTable()
  {
    $quantitiesSubquery = "
      SELECT
        fop.factory_order_id,
        COALESCE(SUM(fos.ordered_quantity), 0) as ordered_quantity_sql,
        COALESCE(SUM(
          CASE WHEN fop.factory_unit_price IS NOT NULL AND fop.factory_unit_price > 0
               THEN fop.factory_unit_price * fos.ordered_quantity ELSE 0 END
        ), 0) as total_price_sql
      FROM factory_orders_products fop
      JOIN factory_orders_sizes fos ON fos.factory_order_product_id = fop.id
      WHERE fop.deleted_at IS NULL
      GROUP BY fop.factory_order_id
    ";
    $arrivalsSubquery = "
      SELECT
        fop.factory_order_id,
        COALESCE(SUM(CASE WHEN a.status = 'ARRIVED' THEN forr.quantity ELSE 0 END), 0) as delivered_quantity_sql,
        COALESCE(SUM(CASE WHEN a.status != 'ARRIVED' THEN forr.quantity ELSE 0 END), 0) as next_arrivals_quantity_sql
      FROM factory_orders_products fop
      JOIN factory_orders_sizes fos ON fos.factory_order_product_id = fop.id
      JOIN factory_orders_restockings forr ON forr.factory_order_size_id = fos.id
      JOIN restockings r ON r.id = forr.restocking_id
      JOIN arrivals a ON a.id = r.arrival_id
      WHERE fop.deleted_at IS NULL
      GROUP BY fop.factory_order_id
    ";

    // Fetch all rows from the new SQL approach
    $sqlRows = DB::table('factory_orders')
      ->select([
        'factory_orders.id',
        DB::raw("COALESCE(fq.ordered_quantity_sql, 0)        as ordered_quantity_sql"),
        DB::raw("COALESCE(fa.delivered_quantity_sql, 0)      as delivered_quantity_sql"),
        DB::raw("COALESCE(fa.next_arrivals_quantity_sql, 0)  as next_arrivals_quantity_sql"),
        DB::raw("COALESCE(fq.total_price_sql, 0)             as total_price_sql"),
      ])
      ->leftJoin(DB::raw("({$quantitiesSubquery}) as fq"), 'fq.factory_order_id', '=', 'factory_orders.id')
      ->leftJoin(DB::raw("({$arrivalsSubquery}) as fa"),  'fa.factory_order_id', '=', 'factory_orders.id')
      ->whereNull('factory_orders.deleted_at')
      ->get();

    // Index by id for O(1) lookup
    $sqlById = [];
    foreach ($sqlRows as $row) {
      $sqlById[$row->id] = $row;
    }

    $discrepancies = [];
    $checked       = 0;
    $total         = FactoryOrder::whereNull('deleted_at')->count();

    $tracker = ProgressTracker::getTracker();
    $tracker->setClientMessage("Comparaison des commandes de fabrication avec la base de données");
    $tracker->setTargetCount($total);

    // Compare against Eloquent accessors, chunk to avoid memory exhaustion
    FactoryOrder::whereNull('deleted_at')->chunk(50, function ($orders) use (&$discrepancies, &$checked, $sqlById, $tracker) {
        foreach ($orders as $fo) {
        $checked++;
        $tracker->addCount();
        $id = $fo->id;

        if (!isset($sqlById[$id])) {
          $discrepancies[] = ['id' => $id, 'issue' => 'missing from SQL result'];
          continue;
        }

        $sql = $sqlById[$id];

        $fields = [
          'ordered_quantity'       => [(int) $fo->ordered_quantity,       (int) $sql->ordered_quantity_sql],
          'delivered_quantity'     => [(int) $fo->delivered_quantity,     (int) $sql->delivered_quantity_sql],
          'next_arrivals_quantity' => [(int) $fo->next_arrivals_quantity, (int) $sql->next_arrivals_quantity_sql],
          'total_price'            => [round((float) $fo->total_price, 2), round((float) $sql->total_price_sql, 2)],
        ];

        foreach ($fields as $field => $values) {
          if ($values[0] !== $values[1]) {
            $discrepancies[] = [
              'id'       => $id,
              'field'    => $field,
              'eloquent' => $values[0],
              'sql'      => $values[1],
              'diff'     => $values[1] - $values[0],
            ];
          }
        }
      }
    });

    $result = [
      'checked'        => $checked,
      'discrepancies'  => count($discrepancies),
      'details'        => $discrepancies,
    ];

    $tracker->stop();
    DebugLog::logJson('compareFactoryOrderDataTable', $result);

    return response()->json($result);
  }
}

