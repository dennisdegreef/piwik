<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Actions;

use Exception;
use Piwik\API\Request;
use Piwik\Archive;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Metrics as PiwikMetrics;
use Piwik\Piwik;
use Piwik\Plugin\Report;
use Piwik\Plugins\Actions\Columns\Metrics\AveragePageGenerationTime;
use Piwik\Plugins\Actions\Columns\Metrics\AverageTimeOnPage;
use Piwik\Plugins\Actions\Columns\Metrics\BounceRate;
use Piwik\Plugins\Actions\Columns\Metrics\ExitRate;
use Piwik\Plugins\CustomVariables\API as APICustomVariables;
use Piwik\Plugins\Actions\Actions\ActionSiteSearch;
use Piwik\Tracker\Action;
use Piwik\Tracker\PageUrl;

/**
 * The Actions API lets you request reports for all your Visitor Actions: Page URLs, Page titles (Piwik Events),
 * File Downloads and Clicks on external websites.
 *
 * For example, "getPageTitles" will return all your page titles along with standard <a href='http://piwik.org/docs/analytics-api/reference/#toc-metric-definitions' rel='noreferrer' target='_blank'>Actions metrics</a> for each row.
 *
 * It is also possible to request data for a specific Page Title with "getPageTitle"
 * and setting the parameter pageName to the page title you wish to request.
 * Similarly, you can request metrics for a given Page URL via "getPageUrl", a Download file via "getDownload"
 * and an outlink via "getOutlink".
 *
 * Note: pageName, pageUrl, outlinkUrl, downloadUrl parameters must be URL encoded before you call the API.
 * @method static \Piwik\Plugins\Actions\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /**
     * Returns the list of metrics (pages, downloads, outlinks)
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param bool|string $segment
     * @param bool|array $columns
     * @return DataTable
     */
    public function get($idSite, $period, $date, $segment = false, $columns = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $report = Report::factory("Actions", "get");
        $archive = Archive::build($idSite, $period, $date, $segment);

        $requestedColumns = Piwik::getArrayFromApiParameter($columns);
        $columns = $report->getMetricsRequiredForReport($allColumns = null, $requestedColumns);

        $inDbColumnNames = array_map(function ($value) { return 'Actions_' . $value; }, $columns);
        $dataTable = $archive->getDataTableFromNumeric($inDbColumnNames);

        $dataTable->deleteColumns(array_diff($requestedColumns, $columns));

        $newNameMapping = array_combine($inDbColumnNames, $columns);
        $dataTable->filter('ReplaceColumnNames', array($newNameMapping));

        $columnsToShow = $requestedColumns ?: $report->getAllMetrics();
        $dataTable->queueFilter('ColumnDelete', array($columnsToRemove = array(), $columnsToShow));

        return $dataTable;
    }

    /**
     * @param int $idSite
     * @param string $period
     * @param Date $date
     * @param bool $segment
     * @param bool $expanded
     * @param bool|int $idSubtable
     * @param bool|int $depth
     *
     * @return DataTable|DataTable\Map
     */
    public function getPageUrls($idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = false,
                                $depth = false)
    {
        $dataTable = $this->getDataTableFromArchive('Actions_actions_url', $idSite, $period, $date, $segment, $expanded, $idSubtable, $depth);
        $this->filterActionsDataTable($dataTable, $expanded);
        return $dataTable;
    }

    /**
     * @param int $idSite
     * @param string $period
     * @param Date $date
     * @param bool $segment
     * @param bool $expanded
     * @param bool $idSubtable
     *
     * @return DataTable|DataTable\Map
     */
    public function getPageUrlsFollowingSiteSearch($idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = false)
    {
        $dataTable = $this->getPageUrls($idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->keepPagesFollowingSearch($dataTable);
        return $dataTable;
    }

    /**
     * @param int $idSite
     * @param string $period
     * @param Date $date
     * @param bool $segment
     * @param bool $expanded
     * @param bool $idSubtable
     *
     * @return DataTable|DataTable\Map
     */
    public function getPageTitlesFollowingSiteSearch($idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = false)
    {
        $dataTable = $this->getPageTitles($idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->keepPagesFollowingSearch($dataTable);
        return $dataTable;
    }

    /**
     * @param DataTable $dataTable
     */
    protected function keepPagesFollowingSearch($dataTable)
    {
        // Keep only pages which are following site search
        $dataTable->filter('ColumnCallbackDeleteRow', array(
            'nb_hits_following_search',
            function ($value) {
                return $value <= 0;
            }
        ));
    }

    /**
     * Returns a DataTable with analytics information for every unique entry page URL, for
     * the specified site, period & segment.
     */
    public function getEntryPageUrls($idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = false)
    {
        $dataTable = $this->getPageUrls($idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->filterNonEntryActions($dataTable);
        return $dataTable;
    }

    /**
     * Returns a DataTable with analytics information for every unique exit page URL, for
     * the specified site, period & segment.
     */
    public function getExitPageUrls($idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = false)
    {
        $dataTable = $this->getPageUrls($idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->filterNonExitActions($dataTable);
        return $dataTable;
    }

    public function getPageUrl($pageUrl, $idSite, $period, $date, $segment = false)
    {
        $callBackParameters = array('Actions_actions_url', $idSite, $period, $date, $segment, $expanded = false, $idSubtable = false);
        $dataTable = $this->getFilterPageDatatableSearch($callBackParameters, $pageUrl, Action::TYPE_PAGE_URL);
        $this->addPageProcessedMetrics($dataTable);
        $this->filterActionsDataTable($dataTable);
        return $dataTable;
    }

    public function getPageTitles($idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = false)
    {
        $dataTable = $this->getDataTableFromArchive('Actions_actions', $idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->filterActionsDataTable($dataTable, $expanded);
        return $dataTable;
    }

    /**
     * Returns a DataTable with analytics information for every unique entry page title
     * for the given site, time period & segment.
     */
    public function getEntryPageTitles($idSite, $period, $date, $segment = false, $expanded = false,
                                       $idSubtable = false)
    {
        $dataTable = $this->getPageTitles($idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->filterNonEntryActions($dataTable);
        return $dataTable;
    }

    /**
     * Returns a DataTable with analytics information for every unique exit page title
     * for the given site, time period & segment.
     */
    public function getExitPageTitles($idSite, $period, $date, $segment = false, $expanded = false,
                                      $idSubtable = false)
    {
        $dataTable = $this->getPageTitles($idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->filterNonExitActions($dataTable);
        return $dataTable;
    }

    public function getPageTitle($pageName, $idSite, $period, $date, $segment = false)
    {
        $callBackParameters = array('Actions_actions', $idSite, $period, $date, $segment, $expanded = false, $idSubtable = false);
        $dataTable = $this->getFilterPageDatatableSearch($callBackParameters, $pageName, Action::TYPE_PAGE_TITLE);
        $this->addPageProcessedMetrics($dataTable);
        $this->filterActionsDataTable($dataTable);
        return $dataTable;
    }

    public function getDownloads($idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = false)
    {
        $dataTable = $this->getDataTableFromArchive('Actions_downloads', $idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->filterActionsDataTable($dataTable, $expanded);
        return $dataTable;
    }

    public function getDownload($downloadUrl, $idSite, $period, $date, $segment = false)
    {
        $callBackParameters = array('Actions_downloads', $idSite, $period, $date, $segment, $expanded = false, $idSubtable = false);
        $dataTable = $this->getFilterPageDatatableSearch($callBackParameters, $downloadUrl, Action::TYPE_DOWNLOAD);
        $this->filterActionsDataTable($dataTable);
        return $dataTable;
    }

    public function getOutlinks($idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = false)
    {
        $dataTable = $this->getDataTableFromArchive('Actions_outlink', $idSite, $period, $date, $segment, $expanded, $idSubtable);
        $this->filterActionsDataTable($dataTable, $expanded);
        return $dataTable;
    }

    public function getOutlink($outlinkUrl, $idSite, $period, $date, $segment = false)
    {
        $callBackParameters = array('Actions_outlink', $idSite, $period, $date, $segment, $expanded = false, $idSubtable = false);
        $dataTable = $this->getFilterPageDatatableSearch($callBackParameters, $outlinkUrl, Action::TYPE_OUTLINK);
        $this->filterActionsDataTable($dataTable);
        return $dataTable;
    }

    public function getSiteSearchKeywords($idSite, $period, $date, $segment = false)
    {
        $dataTable = $this->getSiteSearchKeywordsRaw($idSite, $period, $date, $segment);
        $dataTable->deleteColumn(PiwikMetrics::INDEX_SITE_SEARCH_HAS_NO_RESULT);
        $this->filterActionsDataTable($dataTable);
        $this->addPagesPerSearchColumn($dataTable);
        return $dataTable;
    }

    /**
     * Visitors can search, and then click "next" to view more results. This is the average number of search results pages viewed for this keyword.
     *
     * @param DataTable|DataTable\Simple|DataTable\Map $dataTable
     * @param string $columnToRead
     */
    protected function addPagesPerSearchColumn($dataTable, $columnToRead = 'nb_hits')
    {
        $dataTable->filter('ColumnCallbackAddColumnQuotient', array('nb_pages_per_search', $columnToRead, 'nb_visits', $precision = 1));
    }

    protected function getSiteSearchKeywordsRaw($idSite, $period, $date, $segment)
    {
        $dataTable = $this->getDataTableFromArchive('Actions_sitesearch', $idSite, $period, $date, $segment, $expanded = false);
        return $dataTable;
    }

    public function getSiteSearchNoResultKeywords($idSite, $period, $date, $segment = false)
    {
        $dataTable = $this->getSiteSearchKeywordsRaw($idSite, $period, $date, $segment);
        // Delete all rows that have some results
        $dataTable->filter('ColumnCallbackDeleteRow',
            array(
                PiwikMetrics::INDEX_SITE_SEARCH_HAS_NO_RESULT,
                function ($value) {
                    return $value < 1;
                }
            ));
        $dataTable->deleteRow(DataTable::ID_SUMMARY_ROW);
        $dataTable->deleteColumn(PiwikMetrics::INDEX_SITE_SEARCH_HAS_NO_RESULT);
        $this->filterActionsDataTable($dataTable);
        $this->addPagesPerSearchColumn($dataTable);
        return $dataTable;
    }

    /**
     * @param int $idSite
     * @param string $period
     * @param Date $date
     * @param bool $segment
     *
     * @return DataTable|DataTable\Map
     */
    public function getSiteSearchCategories($idSite, $period, $date, $segment = false)
    {
        Actions::checkCustomVariablesPluginEnabled();
        $customVariables = APICustomVariables::getInstance()->getCustomVariables($idSite, $period, $date, $segment, $expanded = false, $_leavePiwikCoreVariables = true);

        $customVarNameToLookFor = ActionSiteSearch::CVAR_KEY_SEARCH_CATEGORY;

        $dataTable = new DataTable();
        // Handle case where date=last30&period=day
        // FIXMEA: this logic should really be refactored somewhere, this is ugly!
        if ($customVariables instanceof DataTable\Map) {
            $dataTable = $customVariables->getEmptyClone();

            $customVariableDatatables = $customVariables->getDataTables();
            foreach ($customVariableDatatables as $key => $customVariableTableForDate) {
                // we do not enter the IF, in the case idSite=1,3 AND period=day&date=datefrom,dateto,
                if ($customVariableTableForDate instanceof DataTable
                    && $customVariableTableForDate->getMetadata(Archive\DataTableFactory::TABLE_METADATA_PERIOD_INDEX)
                ) {
                    $row = $customVariableTableForDate->getRowFromLabel($customVarNameToLookFor);
                    if ($row) {
                        $dateRewrite = $customVariableTableForDate->getMetadata(Archive\DataTableFactory::TABLE_METADATA_PERIOD_INDEX)->getDateStart()->toString();
                        $idSubtable = $row->getIdSubDataTable();
                        $categories = APICustomVariables::getInstance()->getCustomVariablesValuesFromNameId($idSite, $period, $dateRewrite, $idSubtable, $segment);
                        $dataTable->addTable($categories, $key);
                    }
                }
            }
        } elseif ($customVariables instanceof DataTable) {
            $row = $customVariables->getRowFromLabel($customVarNameToLookFor);
            if ($row) {
                $idSubtable = $row->getIdSubDataTable();
                $dataTable = APICustomVariables::getInstance()->getCustomVariablesValuesFromNameId($idSite, $period, $date, $idSubtable, $segment);
            }
        }
        $this->filterActionsDataTable($dataTable);
        $this->addPagesPerSearchColumn($dataTable, $columnToRead = 'nb_actions');
        return $dataTable;
    }

    /**
     * Will search in the DataTable for a Label matching the searched string
     * and return only the matching row, or an empty datatable
     */
    protected function getFilterPageDatatableSearch($callBackParameters, $search, $actionType, $table = false,
                                                    $searchTree = false)
    {
        if ($searchTree === false) {
            // build the query parts that are searched inside the tree
            if ($actionType == Action::TYPE_PAGE_TITLE) {
                $searchedString = Common::unsanitizeInputValue($search);
            } else {
                $idSite = $callBackParameters[1];
                try {
                    $searchedString = PageUrl::excludeQueryParametersFromUrl($search, $idSite);
                } catch (Exception $e) {
                    $searchedString = $search;
                }
            }
            ArchivingHelper::reloadConfig();
            $searchTree = ArchivingHelper::getActionExplodedNames($searchedString, $actionType);
        }

        if ($table === false) {
            // fetch the data table
            $table = call_user_func_array(array($this, 'getDataTableFromArchive'), $callBackParameters);

            if ($table instanceof DataTable\Map) {
                // search an array of tables, e.g. when using date=last30
                // note that if the root is an array, we filter all children
                // if an array occurs inside the nested table, we only look for the first match (see below)
                $dataTableMap = $table->getEmptyClone();

                foreach ($table->getDataTables() as $label => $subTable) {
                    $newSubTable = $this->doFilterPageDatatableSearch($callBackParameters, $subTable, $searchTree);

                    $dataTableMap->addTable($newSubTable, $label);
                }

                return $dataTableMap;
            }
        }

        return $this->doFilterPageDatatableSearch($callBackParameters, $table, $searchTree);
    }

    /**
     * This looks very similar to LabelFilter.php should it be refactored somehow? FIXME
     */
    protected function doFilterPageDatatableSearch($callBackParameters, $table, $searchTree)
    {
        // filter a data table array
        if ($table instanceof DataTable\Map) {
            foreach ($table->getDataTables() as $subTable) {
                $filteredSubTable = $this->doFilterPageDatatableSearch($callBackParameters, $subTable, $searchTree);

                if ($filteredSubTable->getRowsCount() > 0) {
                    // match found in a sub table, return and stop searching the others
                    return $filteredSubTable;
                }
            }

            // nothing found in all sub tables
            return new DataTable;
        }

        // filter regular data table
        if ($table instanceof DataTable) {
            // search for the first part of the tree search
            $search = array_shift($searchTree);
            $row = $table->getRowFromLabel($search);
            if ($row === false) {
                // not found
                $result = new DataTable;
                $result->setAllTableMetadata($table->getAllTableMetadata());
                return $result;
            }

            // end of tree search reached
            if (count($searchTree) == 0) {
                $result = new DataTable();
                $result->addRow($row);
                $result->setAllTableMetadata($table->getAllTableMetadata());
                return $result;
            }

            // match found on this level and more levels remaining: go deeper
            $idSubTable = $row->getIdSubDataTable();
            $callBackParameters[6] = $idSubTable;

            /**
             * @var \Piwik\Period $period
             */
            $period = $table->getMetadata('period');
            if (!empty($period)) {
                $callBackParameters[3] = $period->getDateStart() . ',' . $period->getDateEnd();
            }

            $table = call_user_func_array(array($this, 'getDataTableFromArchive'), $callBackParameters);
            return $this->doFilterPageDatatableSearch($callBackParameters, $table, $searchTree);
        }

        throw new Exception("For this API function, DataTable " . get_class($table) . " is not supported");
    }

    /**
     * Common filters for all Actions API
     *
     * @param DataTable|DataTable\Simple|DataTable\Map $dataTable
     * @param bool $expanded
     */
    protected function filterActionsDataTable($dataTable, $expanded = false)
    {
        // Must be applied before Sort in this case, since the DataTable can contain both int and strings indexes
        // (in the transition period between pre 1.2 and post 1.2 datatable structure)
        $dataTable->filter('ReplaceColumnNames');
        $dataTable->filter('Sort', array('nb_visits', 'desc', $naturalSort = false, $expanded));
        $dataTable->filter(function (DataTable $dataTable) {
            foreach ($dataTable->getRows() as $row) {
                $url = $row->getMetadata('url');
                if ($url) {
                    $row->setMetadata('segmentValue', urldecode($url));
                }
            }
        });

        $dataTable->filter('GroupBy', array('label', function ($label) {
            return urldecode($label);
        }));

        $dataTable->queueFilter('ReplaceSummaryRowLabel');
    }

    /**
     * Removes DataTable rows referencing actions that were never the first action of a visit.
     *
     * @param DataTable $dataTable
     */
    private function filterNonEntryActions($dataTable)
    {
        $dataTable->filter('ColumnCallbackDeleteRow',
            array('entry_nb_visits',
                  function ($visits) {
                      return !strlen($visits);
                  }
            )
        );
    }

    /**
     * Removes DataTable rows referencing actions that were never the last action of a visit.
     *
     * @param DataTable $dataTable
     */
    private function filterNonExitActions($dataTable)
    {
        $dataTable->filter('ColumnCallbackDeleteRow',
            array('exit_nb_visits',
                  function ($visits) {
                      return !strlen($visits);
                  })
        );
    }

    protected function getDataTableFromArchive($name, $idSite, $period, $date, $segment, $expanded = false, $idSubtable = null, $depth = null)
    {
        $skipAggregationOfSubTables = false;
        if ($period == 'range'
            && empty($idSubtable)
            && empty($expanded)
            && !Request::shouldLoadFlatten()
        ) {
            $skipAggregationOfSubTables = false;
        }
        return Archive::getDataTableFromArchive($name, $idSite, $period, $date, $segment, $expanded, $idSubtable, $skipAggregationOfSubTables, $depth);
    }

    private function addPageProcessedMetrics(DataTable\DataTableInterface $dataTable)
    {
        $dataTable->filter(function (DataTable $table) {
            $extraProcessedMetrics = $table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME);
            $extraProcessedMetrics[] = new AverageTimeOnPage();
            $extraProcessedMetrics[] = new BounceRate();
            $extraProcessedMetrics[] = new ExitRate();
            $extraProcessedMetrics[] = new AveragePageGenerationTime();
            $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, $extraProcessedMetrics);
        });
    }
}
