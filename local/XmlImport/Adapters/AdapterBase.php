<?php

namespace XmlImport\Adapters;

use SimpleXMLElement;
use PO\QueryBuilder;
use XmlImport\Helpers\CurlHelper;
use XmlImport\Runner\Runner;

abstract class AdapterBase
{
    public $runner;
    public $id;
    public $name;
    public $url;
    public $previousRemoteIdMap;
    public $previousChecksumMap;
    public $recordsAdded;
    public $recordsUpdated;
    public $recordsSkipped;
    public $downloads;
    public $sqlSyncFile;
    public $sqlParameters;

    public function __construct(Runner $uRunner, $uConfig)
    {
        $this->runner = $uRunner;

        $this->id = $uConfig["id"];
        $this->name = $uConfig["name"];
        $this->url = $uConfig["url"];
        $this->sqlSyncFile = $uConfig["sql.sync"];

        $this->sqlParameters = [
            ":adapter_id" => $this->id,
            ":adapter_name" => $this->name
        ];
    }

    public function start()
    {
        echo "Adapter {$this->name} is starting...", PHP_EOL;

        echo "- Downloading XML from {$this->url}", PHP_EOL;
        $tFile = $this->downloadSource();
        if ($tFile === false) {
            // TODO throw error
            return false;
        }

        echo "- Resetting Status Values", PHP_EOL;
        $this->recordsAdded = 0;
        $this->recordsUpdated = 0;
        $this->recordsSkipped = 0;
        $this->downloads = [];

        $this->resetStatuses();

        echo "- Processing Data", PHP_EOL;
        $this->loadPreviousMaps();
        $this->processFile($tFile);
        echo "-- Completed: {$this->recordsAdded} added. {$this->recordsUpdated} updated. {$this->recordsSkipped} skipped.", PHP_EOL;

        echo "- Syncing SQL", PHP_EOL;
        $this->syncSql();

        echo "- Downloading Assets", PHP_EOL;
        $this->downloadAssets();
        echo "-- Completed.", PHP_EOL, PHP_EOL;
    }

    public function downloadSource()
    {
        return CurlHelper::downloadFile($this->url);
    }

    public function downloadAssets()
    {
        foreach ($this->downloads as $tDownload) {
            $tLocalFile = "{$tDownload["directory"]}/{$tDownload["file"]}";
            if (!is_dir($tDownload["directory"])) {
                mkdir($tDownload["directory"], 0777, true);
            } else {
                if (file_exists($tLocalFile)) {
                    continue;
                }
            }

            echo "-- Downloading: {$tLocalFile}", PHP_EOL;
            CurlHelper::downloadFile($tDownload["url"], $tLocalFile);
        }
    }

    public function loadPreviousMaps()
    {
        $tSelectQuery = QueryBuilder::factorySelect()
            ->select("ItemId, Checksum, RemoteId")
            ->from("XmlImport")
            ->where("AdapterId", $this->id)
            ->toSql();

        $this->previousRemoteIdMap = [];
        $this->previousChecksumMap = [];

        $tRows = $this->runner->pdo->query($tSelectQuery);
        foreach ($tRows as $tRow) {
            $this->previousRemoteIdMap[$tRow["RemoteId"]] = $tRow["ItemId"];
            $this->previousChecksumMap[$tRow["ItemId"]] = $tRow["Checksum"];
        }
    }

    public function resetStatuses()
    {
        $tUpdateQuery = QueryBuilder::update()
            ->table("XmlImport")
            ->set([
                "Status" => 0
            ])
            ->where("AdapterId", $this->id)
            ->toSql();

        $this->runner->pdo->exec($tUpdateQuery);
    }

    public function syncSql()
    {
        if (strlen($this->sqlSyncFile) === 0) {
            return;
        }

        $tPath = __DIR__ . "/../../../" . $this->sqlSyncFile;
        $tSql = file_get_contents($tPath);

        $tQuery = $this->runner->pdo->prepare($tSql);
        $tQuery->execute($this->sqlParameters);
    }

    public function processFile($uFile)
    {
        $tXml = simplexml_load_file($uFile);
        // TODO throw error if it is not loaded
        return $this->processXml($tXml);
    }

    public abstract function processXml(SimpleXMLElement $uXml);

    protected function addDownload($uAdapterId, $uCategory, $uUrl)
    {
        $tDownload = [
            "url" => $uUrl,
            "directory" => "downloaded/{$uAdapterId}/{$uCategory}",
            "file" => str_replace(
                "/",
                "_",
                parse_url($uUrl, PHP_URL_PATH)
            )
        ];

        $this->downloads[] = $tDownload;

        return $tDownload;
    }

    protected function addRecord($uValues, $uImages = [])
    {
        $tDownload = false;
        $tItemId = 0;

        if (isset($this->previousRemoteIdMap[$uValues["RemoteId"]])) {
            $tPreviousId = $this->previousRemoteIdMap[$uValues["RemoteId"]];
            $tPreviousChecksum = $this->previousChecksumMap[$tPreviousId];

            // update if the record has changed
            if ($tPreviousChecksum != $uValues["Checksum"]) {
                $tUpdateQuery = QueryBuilder::update()
                    ->table("XmlImport")
                    ->set($uValues)
                    ->where("ItemId", $tPreviousId)
                    ->limit(1)
                    ->toSql();

                $this->runner->pdo->exec($tUpdateQuery);

                $this->recordsUpdated++;
            } else {
                $this->recordsSkipped++;
            }
        } else {
            $tInsertQuery = QueryBuilder::insert()
                ->into("XmlImport")
                ->values($uValues)
                ->toSql();

            $this->runner->pdo->exec($tInsertQuery);
            $tItemId = $this->runner->pdo->lastInsertId();

            $this->recordsAdded++;
            $tDownload = true;
        }

        if ($tDownload && $uValues["Status"] != 0) {
            foreach ($uImages as $tImage) {
                $tDownload = $this->addDownload($this->id, "images", $tImage);

                $tInsertImageQuery = QueryBuilder::insert()
                    ->into("XmlImportImages")
                    ->values([
                        "ItemId" => $tItemId,
                        "Url" => "{$tDownload["directory"]}/{$tDownload["file"]}"
                    ])
                    ->toSql();

                $this->runner->pdo->exec($tInsertImageQuery);
            }
        }
    }
}
