<?php

namespace SonarSoftware\FreeRadius;

use Dotenv\Dotenv;
use Exception;
use League\CLImate\CLImate;
use RuntimeException;

class Genie
{
    private $climate;
    public function __construct()
    {
        $dotenv = new Dotenv(__DIR__ . "/../");
        $dotenv->load();
        $dotenv->required("MYSQL_PASSWORD");
        $this->climate = new CLImate;
    }

    /**
     * Prompt for a selection from the default menu.
     * @return mixed
     */
    public function initialSelection()
    {
        $options = [
            '_initial' => 'Initial configuration',
            '_nas' => 'NAS configuration',
            '_mysqlRemote' => 'MySQL remote access configuration',
            '_thirdParty' => 'Third party configuration',
            '_quit' => 'Quit',
        ];
        $input = $this->climate->lightGreen()->radio('Please select an action to perform:', $options);
        $response = $input->prompt();
        while (trim($response) == null)
        {
            $this->climate->shout("Please select an option with spacebar, and then press enter.");
            $response = $input->prompt();
        }

        if ($response !== "_quit")
        {
            $this->climate->lightBlue("OK, moving into {$options[$response]}");
        }
        $this->handleSelection($response);
    }

    /**
     * Handle one of the top level selections
     * @param $selection
     */
    public function handleSelection($selection)
    {
        if (strpos($selection,"_") === 0)
        {
            //Top level selection
            switch ($selection)
            {
                case "_quit":
                    $this->climate->lightBlue("Good bye!");
                    return;
                    break;
                default:
                    $this->handleSubmenu($selection);
                    break;
            }
        }
        else
        {
            $this->handleSubmenu($selection);
        }
    }

    /**
     * Handle the submenu
     * @param $selection
     */
    private function handleSubmenu($selection)
    {
        $options = $this->getOptions($selection);
        $options['back'] = 'Go back one level';
        $input = $this->climate->lightGreen()->radio('Please select an action to perform:', $options);
        $response = $input->prompt();
        while (trim($response) == null)
        {
            $this->climate->shout("Please select an option with spacebar, and then press enter.");
            $response = $input->prompt();
        }
        $this->handleSubmenuSelection($selection, $response);
    }

    /**
     * Build the options for each submenu
     * @param $selection
     * @return array
     */
    private function getOptions($selection)
    {
        $options = [];
        switch ($selection)
        {
            case "_initial":
                $options = [
                    'database' => 'Setup initial database structure',
                    'configure_freeradius' => 'Perform initial FreeRADIUS configuration (will autodetect version of freeradius installed)',
                ];
                break;
            case "_nas":
                $options = [
                    'add' => 'Add NAS',
                    'remove' => 'Remove NAS',
                    'addcoa' => 'Add NAS With COA/POD Endpoint',
                    'removecoa' => 'Remove NAS With COA/POD Endpoint',
                    'list' => 'List NAS entries',
                    'changenaspw' => 'Change NAS password',
                    'changenaspwcoa' => 'Change NAS password with COA/POD endpoint',
                ];
                break;
            case "_mysqlRemote":
                $options = [
                    'enable' => 'Enable remote access',
                    'disable' => 'Disable remote access',
                    'add_user' => 'Add a remote access user',
                    'list_users' => 'List remote access users',
                    'remove_user' => 'Remove a remote access user',
                ];
                break;
            case "_thirdParty":
                $options = [
                    'mimosa' => 'Enable integration with Mimosa',
                ];
                break;
            default:
                break;
        }

        return $options;
    }

    /**
     * Deal with a sub menu
     * @param $subMenuSelection
     */
    private function handleSubmenuSelection($selection, $subMenuSelection)
    {
        switch ($subMenuSelection)
        {
            case "back":
                $this->climate->lightBlue("OK, going back one level.");
                $this->initialSelection();
                break;
            default:
                $this->doSubMenuAction($selection, $subMenuSelection);
                break;
        }
    }

    /**
     * Do whatever action needs to take place as a result of the selection.
     * @param $selection - The top level selection
     * @param $subMenuSelection - The secondary menu selection
     */
    private function doSubMenuAction($selection, $subMenuSelection)
    {
        $databaseSetup = new DatabaseSetup();

        switch ($selection)
        {
            case "_initial":
                switch ($subMenuSelection) {
                    case "database":
                        try {
                            $databaseSetup->createInitialDatabase();
                        }
                        catch (Exception $e)
                        {
                            $this->climate->shout("Failed to create initial database - {$e->getMessage()}");
                        }
                        break;
                    case "configure_freeradius":
                        $freeRadiusSetup = new FreeRadiusSetup();
                        $freeRadiusSetup->configureFreeRadiusToUseSql();
                        break;
                    default:
                        $this->climate->shout("Whoops - no handler defined for this action!");
                        break;
                }
                break;
            case "_nas":
                $nasManagement = new NasManagement();
                switch ($subMenuSelection)
                {
                    case "add":
                        $nasManagement->addNas();
                        break;
                    case "remove":
                        $nasManagement->deleteNas();
                        break;
                    case "addcoa":
                        $nasManagement->addNasCoa();
                        break;
                    case "removecoa":
                        $nasManagement->deleteNasCoa();
                        break;
                    case "list":
                        $nasManagement->listNas();
                        break;
                    case "changenaspw":
                        $nasManagement->changeNasPw();
                        break;
                    case "changenaspwcoa":
                        $nasManagement->changeNasPwCoa();
                        break;
                }
                break;
            case "_mysqlRemote":
                switch ($subMenuSelection)
                {
                    case "enable":
                        $databaseSetup->enableRemoteAccess();
                        break;
                    case "disable":
                        $databaseSetup->disableRemoteAccess();
                        break;
                    case "add_user":
                        $databaseSetup->addRemoteAccessUser();
                        break;
                    case "list_users":
                        $databaseSetup->listRemoteAccessUsers();
                        break;
                    case "remove_user":
                        $databaseSetup->deleteRemoteAccessUser();
                        break;
                }
                break;
            case "_thirdParty":
                switch ($subMenuSelection)
                {
                    case "mimosa":
                        $mimosa = new Mimosa();
                        $mimosa->updateEap();
                        break;
                }
                break;
            default:
                $this->climate->shout("Whoops - no handler defined for this action!");
                break;
        }

        $this->handleSubmenu($selection);
    }
}
