<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of PurchaseAction
 *
 * @author nemo
 */
class PurchaseAction extends CommonAction {
    
    protected $workflowAlias = "purchase";
    
    protected $indexModel = "PurchaseView";
    
    public function insert() {
        $model = D("Purchase");
        $data = $model->formatData($_POST);
        $billId = $model->newBill($data);

        if(!$billId) {
            $this->error($model->getError());
            return;
        }

        import("@.Workflow.Workflow");
        $workflow = new Workflow($this->workflowAlias);
        $node = $workflow->doNext($billId, "", true);
    }

    public function update() {
        $model = D("Purchase");
        $data = $model->formatData($_POST);
        $billId = $model->editBill($data);

        if(!$billId) {
            $this->error($model->getError());
            return;
        }

    }

    public function read() {
        if(!$_GET["includeRows"] or $_GET['workflow']) {
            return parent::read();
        }

//        $this->readModel = "PurchaseView";
//        $formData = parent::read(true);
        $formData = D("PurchaseView")->find($_GET['id']);

        $formData["inputTime"] = $formData["dateline"]*1000;
//        $formData["total_amount"] = $formData["total_price"];
//        $formData["total_amount_real"] = $formData["total_price_real"];
//        $formData["total_num"] = $formData["quantity"];

        $rowModel = D("PurchaseDetailView");
        $rows = $rowModel->where("PurchaseDetail.purchase_id=".$formData["id"])->select();
//        echo $rowModel->getLastSql();exit;
        $modelIds = array();
        $rowData = array();
        foreach($rows as $v) {
            $tmp = explode("-", $v["factory_code_all"]); //根据factory_code_all factory_code - standard - version
            $factory_code = array_shift($tmp);
            $modelIds = array_merge($modelIds, $tmp);

            $v["modelIds"] = $tmp;
            $v["goods_id"] = sprintf("%s_%s_%s", $factory_code, $v["goods_id"], $v["goods_category_id"]); // factory_code, id, catid
            $v["goods_id_label"] = sprintf("%s",$v["goods_name"]);
            $v["amount"] = $v["price"];
            $rowData[$v["id"]] = $v;
        }
//        array_flip(array_flip($modelIds));

        $formData["customer_id_label"] = $formData["customer"];


        $dataModel = D("DataModelDataView");

        $rowData = $dataModel->assignModelData($rowData, $modelIds);

        $formData["rows"] = reIndex($rowData);


        $this->response($formData);
    }
    
}
