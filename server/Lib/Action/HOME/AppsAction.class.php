<?php
/**
 * Created by PhpStorm.
 * User: nemo
 * Date: 14-7-9
 * Time: 1:14
 *
 * Apps 中所有涉及ID均为远程ID
 */

class AppsAction extends CommonAction {

    private $serviceUri;

    public function __construct() {
        parent::__construct();
        $this->serviceUri = DBC("remote.service.uri");
    }

    public function index() {
        import("@.ORG.httplib");
        $http = new httplib();

        //获取所有APP
        if($_GET["queryAll"]) {
            //获取所有APP列表
            $http->request($this->serviceUri."App/getList", array(
                "_pn" => $_GET["_pn"],
                "_ps" => $_GET["_ps"],
                "_kw" => $_GET["_kw"],
                "api_key" => C("SERVICE_API_KEY")
            ));

            $tmp = $http->get_data();
            $response = json_decode($tmp, true);
            if(!$response["count"]) {
                Log::write("get apps list failed");
                $this->error("get apps list failed");
                return;
            }
            $allApps = $response["apps"];

            $model = D("Apps");
            $tmp = $model->select();
            foreach($tmp as $app) {
                $installedApps[] = $app["alias"];
                $installedAppVersions[$app["alias"]] = $app["version"];
            }

            foreach($allApps as $k=>$app) {
                $allApps[$k]["status"] = "appStatus";
                if(in_array($app["alias"], $installedApps)) {
                    $allApps[$k]["status"] .= "installed";
                    $allApps[$k]["installed"] = true;
                    if($app["latest_version"] > $installedAppVersions[$app["alias"]]) {
                        $allApps[$k]["status"] .= "hasUpdate";
                        $allApps[$k]["hasUpdate"] = true;
                    }
                }
            }

            $allApps = reIndex($allApps);

            if($_GET["_ic"]) {
                $response = array(
                    array("count" => $response["count"]),
                    $allApps
                );
            } else {
                $response = $allApps;
            }

            $this->response($response);

        } else {

            $tmp = parent::index(true);

            if(!$tmp) {
                $this->response(array(
                    array("count"=>0),
                    array()
                ));
                return;
            }

            foreach($tmp as $app) {
                $installedApps[$app["alias"]] = $app;
                $installedAppAlias[] = $app["alias"];
            }

            $http->request($this->serviceUri."App/getList", array(
                "alias" => implode(",", $installedAppAlias),
                "api_key" => C("SERVICE_API_KEY")

            ));

            $tmp = $http->get_data();
            $response = json_decode($tmp, true);

            foreach($response["apps"] as $appMeta) {
                $installedApps[$appMeta["alias"]]["id"] = $appMeta["id"];
                $installedApps[$appMeta["alias"]]["name"] = $appMeta["name"];
                $installedApps[$appMeta["alias"]]["author"] = $appMeta["author"];
                $installedApps[$appMeta["alias"]]["link"] = $appMeta["link"];
                $installedApps[$appMeta["alias"]]["description"] = $appMeta["description"];
                $installedApps[$appMeta["alias"]]["status"] = "appStatusinstalled";

                if($appMeta["latest_version"] > $installedApps[$appMeta["alias"]]["version"]) {
                    $installedApps[$appMeta["alias"]]["status"] .= "hasUpdate";
                }
            }

            $count = D("Apps")->where($this->queryMeta["map"])->count("id");
//            print_r($installedApps);exit;
            $this->response(array(
                array("count"=>$count),
                reIndex($installedApps)
            ));
            return;
        }

    }

    public function read() {
        import("@.ORG.httplib");
        $http = new httplib();

        $params = array(
            "api_key" => C('SERVICE_API_KEY')
        );


        if($_GET["id"]) {
            $params["id"] = abs(intval($_GET["id"]));
        }
        if($_GET["alias"]) {
            $params["alias"] = $_GET["alias"];
        }

        $http->request($this->serviceUri."App/getInfo", $params);

        $tmp = $http->get_data();
//        echo $tmp;
        $appInfo = json_decode($tmp, true);

        $model = D("Apps");
        $installed = $model->where(array("alias" => $appInfo["alias"]))->find();

        if($installed) {
            $appInfo["installed"] = true;
            $appInfo["hasUpdate"] = $appInfo["latest_version"] > $installed["version"] ? true : false;
            $appInfo["currentVersion"] = $installed["version"];
            $appInfo["status"] = $installed["status"];
        }

        $this->response($appInfo);


    }

    /*
     * 卸载
     * **/
    public function delete() {
        $alias = $_GET["id"];

        $appsConf = F("appConf");

        $buildClassName = sprintf("%sBuild", ucfirst($alias));

        import("@.ORG.CommonBuildAction");
        $buildFile = sprintf("%s/apps/%s/backend/%s.class.php", ROOT_PATH, $alias, $buildClassName);

        if(!is_file($buildFile)) {
            $buildClassName = "CommonBuildAction";
        } else {
            include $buildFile;
        }

        $buildClass = new $buildClassName($appsConf[$alias]);

        $buildClass->appUninstall();

        $buildClass->afterAppUninstall();

        unset($_GET["id"]);
        $_GET["alias"] = $alias;
        $this->read();
    }

    /*
     * 安装
     * 步骤：
     *  1. 根据APP alias 获取远程下载地址
     *  2. 下载ZIP文件
     *  3. 解压
     *  4. 复制到/apps/目录
     *  5. 解析config.json
     *  5. 安装依赖
     *  5. 执行/apps/alias/backend/AliasBuild 中得 appInstall方法
     *  6. 执行AppBuild:: afterAppInstall() 方法
     *  7. 删除zip包 删除临时目录
     * **/
    public function insert() {
        $alias = $_REQUEST["alias"];

        $remoteUri = sprintf("%sApp/getDownload/alias/%s/api_key/%s",
            $this->serviceUri,
            $alias,
            C("SERVICE_API_KEY")
        );

        $target = ROOT_PATH."/apps";
        list($localPath, $tmpFolder) = $this->downloadAndZipAndCopy($remoteUri, $target);

        $appDir = $target."/".$alias;

        if(!is_dir($appDir)) {
            $this->installClean($localPath, $tmpFolder);
            Log::write("Install app failed while copy: ". $alias);
            $this->error("install failed while copy");
            return;
        }

        $appConf = $this->beforeBuild($appDir);

        $this->appBuild($appConf);

        $this->installClean();

        //删除安装SQL文件
        unlink($appDir."/data/sqls/install.sql");

        $_GET["alias"] = $alias;
        $this->read();

    }

    /*
     * 更新应用状态（禁用/启用）
     * **/
    public function updateStatus() {
        $model = D("Apps");
        $model->where(array(
            "alias" => $_POST["alias"]
        ))->save(array("status"=>$_POST["status"] ? 1 : 0));

        unset($_GET["id"]);
        $_GET["alias"] = $_POST["alias"];

        $this->read();
    }

    /*
     * APP升级
     * **/
    public function update() {
        if(!$_GET["upgrade"]) {
            $this->updateStatus();
        }

        $alias = $_REQUEST["alias"];

        $appInfo = D("Apps")->where(array(
            "alias"=> $alias
        ))->find();

        $remoteUri = sprintf("%sApp/getUpgrade/alias/%s/api_key/%s/oldVersion/".$appInfo["version"],
            $this->serviceUri,
            $alias,
            C("SERVICE_API_KEY")
        );

        $target = ROOT_PATH."/apps/";

        $downloadResult = $this->downloadAndZipAndCopy($remoteUri, $target);
        if(!$downloadResult) {
            return;
        }

        $appDir = $target.$alias;

        if(!is_dir($appDir)) {
            $this->installClean();
            Log::write("Install app failed while copy: ". $alias);
            $this->error("install failed while copy");
            return;
        }

        $appConf = $this->beforeBuild($appDir);

        $this->appBuild($appConf, "upgrade");

        $this->installClean();

        //删除更新SQL文件
        unlink($appDir."/data/sqls/upgrade.sql");

        $_GET["alias"] = $alias;
        $this->read();

    }

    /*
     * 清除安装临时文件
     * @param $localPath 下载zip文件
     * @param $tmpFolder 解压临时目录
     * **/
    private function installClean() {
        $path = ENTRY_PATH."/Data/apps";
        delDirAndFile($path);
        mkdir($path, 0777);
    }


    /*
     * 下载并解压文件，然后复制到某目录
     * **/
    private function downloadAndZipAndCopy($remoteUri, $target) {
        $localName = md5(CTS).".zip";
        $localPath = ENTRY_PATH."/Data/apps/".$localName;

        import("ORG.Net.Http");
        Http::curlDownload($remoteUri, $localPath);

        //下载不成功
        if(!is_file($localPath) or filesize($localPath) <= 0) {
            Log::write("Install app failed while download: ". $remoteUri);
            $this->error("install failed while download");
            return false;
        }

        $zip = new ZipArchive();
        $tmpFolder = ENTRY_PATH."/Data/apps/installTmp";
        $rs = $zip->open($localPath);
        if($rs === true) {
            if(!is_dir($tmpFolder)) {
                mkdir($tmpFolder, 0777);
            }
            $zip->extractTo($tmpFolder);
        }
        $zip->close();

        recursionCopy($tmpFolder, $target);

        return array($localPath, $tmpFolder);
    }

    /*
     * 应用构建前执行动作
     * **/
    private function beforeBuild($appDir) {
        $appConf = json_decode(file_get_contents($appDir."/config.json"), true);

        if(!$appConf) {
            $this->installClean();
            Log::write("Install app failed parse config: ". $appConf);
            $this->error("install failed while parse config");
            return;
        }

        $loadedApp = F("loadedApp");
        foreach($appConf["requirements"] as $req) {
            if(!in_array($req, $loadedApp)) {
                $requirements[] = $req;
            }
        }
        if($requirements) {
            $this->installClean();
            $this->response(array(
                "type" => "requirements",
                "requirements" => implode(",", $requirements)
            ));
            return false;
        }

        return $appConf;
    }

    /*
     * 应用构建动作
     * **/
    private function appBuild($appConf, $action="install") {
        $alias = $appConf["alias"];
        $buildFile = sprintf("%s/apps/%s/backend/%sBuild.class.php", ROOT_PATH, $alias, ucfirst($alias));

        $buildClassName = ucfirst($alias)."Build";

        import("@.ORG.CommonBuildAction");
        if(!is_file($buildFile)) {
            $buildClassName = "CommonBuildAction";
        } else {
            include $buildFile;
        }

        $buildClass = new $buildClassName($appConf);

        $method = "app".ucfirst($action);
        if(!$buildClass->$method($alias)) {
            $this->installClean();
            $this->error("install failed while install");
            return;
        }

        $afterMethod = "afterApp".ucfirst($action);
        $buildClass->$afterMethod();

        return $buildClass;
    }

}

