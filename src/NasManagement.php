<?php

namespace SonarSoftware\FreeRadius;

use League\CLImate\CLImate;
use PDO;
use RuntimeException;

class NasManagement
{
    private $dbh;
    private $climate;
    public function __construct()
    {
        $this->dbh = new \PDO('mysql:dbname=radius;host=localhost', 'root', getenv('MYSQL_PASSWORD'));
        $this->climate = new CLImate;

        $this->goBack = "(ENTER by itself to go back):";
    }

    /**
     * Add a new NAS to the database without a coa endpoint
     */
    public function addNas()
    {
        $input = $this->climate->lightBlue()->input("Enter NAS IP address $this->goBack");
        $ipAddress = null;
        while ($ipAddress == null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
        {
            $ipAddress = $input->prompt();
            if ($ipAddress == null)
            {
                return;
            }
            elseif (filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
            {
                $this->climate->shout("That IP address is not valid.");
            }
        }

        $sth = $this->dbh->prepare("SELECT shortname FROM nas WHERE nasname=?");
        $sth->execute([$ipAddress]);
        $result = $sth->fetch(PDO::FETCH_ASSOC);
        if ($result !== false)
        {
            $this->climate->shout("There is already a NAS with that IP named {$result['shortname']}. Please enter a different IP, or remove this NAS first.");
            $this->addNas();
        }

        $input = $this->climate->lightBlue()->input("Enter a short name for this NAS $this->goBack");
        $name = null;
        while ($name == null)
        {
            $name = $input->prompt();
            if ($name == null)
            {
                return;
            }
        }

        $name = preg_replace("/[^A-Za-z0-9-]/", "", $name);

        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $characters[rand(0, strlen($characters) - 1)];
        }

        $sth = $this->dbh->prepare("INSERT INTO nas (nasname, shortname, type, secret, description, coa) VALUES(?,?,?,?,?,?)");
        if ($sth->execute([$ipAddress, substr($name,0,255), 'other', $secret, 'Added via the Sonar FreeRADIUS Genie tool',0]))
        {
            $this->climate->bold()->magenta("Added the NAS $ipAddress with a random secret of $secret - record this secret, you will need it shortly!");
        }
        else
        {
            $this->climate->shout("Failed to add the NAS $ipAddress with secret of $secret to the database.");
            return;
        }

        try {
            CommandExecutor::executeCommand("/usr/sbin/service freeradius restart");
        }
        catch (RuntimeException $e)
        {
            $this->climate->shout("Failed to restart FreeRADIUS: {$e->getMessage()}");
        }
    }

    /**
     * Add a new NAS to the database with a coa endpoint
     */
    public function addNasCoa()
    {
        $input = $this->climate->lightBlue()->input("Enter NAS IP address $this->goBack");
        $ipAddress = null;
        while ($ipAddress == null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
        {
            $ipAddress = $input->prompt();
            if ($ipAddress == null)
            {
                return;
            }
            elseif (filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
            {
                $this->climate->shout("That IP address is not valid.");
            }
        }

        $sth = $this->dbh->prepare("SELECT shortname FROM nas WHERE nasname=?");
        $sth->execute([$ipAddress]);
        $result = $sth->fetch(PDO::FETCH_ASSOC);
        if ($result !== false)
        {
            $this->climate->shout("There is already a NAS with that IP named {$result['shortname']}. Please enter a different IP, or remove this NAS first.");
            $this->addNasCoa();
        }

        $input = $this->climate->lightBlue()->input("Enter short name for this NAS $this->goBack");
        $name = $input->prompt();
        if ($name == null)
        {
            return;
        }

        $name = preg_replace("/[^A-Za-z0-9-]/", "", $name);

        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $characters[rand(0, strlen($characters) - 1)];
        }

        $v = `dpkg -s freeradius | grep Version`;
        $v = explode(" ",$v);
        $c = "/../conf/" . substr($v[1],0,1) . ".0/";

        $sth = $this->dbh->prepare("INSERT INTO nas (nasname, shortname, type, secret, description,coa) VALUES(?,?,?,?,?,?)");
        if ($sth->execute([$ipAddress, substr($name,0,255), 'other', $secret, 'Added via the Sonar FreeRADIUS Genie tool',1]))
        {
            $this->climate->bold()->magenta("Added the NAS $ipAddress with a random secret of $secret - record this secret, you will need it shortly!");
            CommandExecutor::executeCommand("/bin/cp " . __DIR__ . $c . "sites-config/coa-relay/homeservers/homeserver-template.conf /etc/freeradius/sites-config/coa-relay/homeservers/$name.conf");
            CommandExecutor::executeCommand("/bin/sed -i 's/ipaddr = nas-ip-address/ipaddr = $ipAddress/g' /etc/freeradius/sites-config/coa-relay/homeservers/$name.conf");
            CommandExecutor::executeCommand("/bin/sed -i 's/secret = nas-secure-secret/secret = $secret/g' /etc/freeradius/sites-config/coa-relay/homeservers/$name.conf");
            CommandExecutor::executeCommand("/bin/sed -i 's/home_server coa-nas-short-name/home_server $name/g' /etc/freeradius/sites-config/coa-relay/homeservers/$name.conf");

            $sth = $this->dbh->prepare("SELECT GROUP_CONCAT(DISTINCT shortname ) FROM nas WHERE coa=TRUE;");
            $sth->execute();
            $result = $sth->fetch(PDO::FETCH_ASSOC);
            if ($result !== false)
            {
//              print_r($result);
                $this->climate->shout("home_server = {$result['GROUP_CONCAT(DISTINCT shortname )']}");
                $coaepl = ("localhost-coa,{$result['GROUP_CONCAT(DISTINCT shortname )']}");
            CommandExecutor::executeCommand("/bin/sed -i 's/home_server = .*/home_server = $coaepl/g' /etc/freeradius/sites-config/coa-relay/pool.conf");
            }
        }
        else
        {
            $this->climate->shout("Failed to add the NAS $ipAddress with secret of $secret to the database.");
            return;
        }

        try {
            CommandExecutor::executeCommand("/usr/sbin/service freeradius restart");
        }
        catch (RuntimeException $e)
        {
            $this->climate->shout("Failed to restart FreeRADIUS: {$e->getMessage()}");
        }
    }

    /**
     * List all the NAS showing the ip shortname secret coa
     */
    public function listNas()
    {
        $sth = $this->dbh->prepare("SELECT id, nasname, shortname, secret, coa  FROM nas ORDER BY id ASC");
        $sth->execute();

        $all_nas_entries = [];
        foreach ($sth->fetchAll() as $x => $record)
        {
            $all_nas_entries[$x] = [
                'IP address' => $record['nasname'],
                'Short name' => $record['shortname'],
                'Secret' => $record['secret'],
            ];
            $all_nas_entries[$x]['COA enabled'] = ($record['coa']) ? "Yes" : "";
        }
        $this->climate->table($all_nas_entries);
    }

    /**
    * change the password of a nas without a coa endpoint
    */
    public function changeNasPw()
     {
        $input = $this->climate->lightBlue()->input("Enter the NAS IP address $this->goBack");
        $ipAddress = null;
        while ($ipAddress == null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
        {
            $ipAddress = $input->prompt();
            if ($ipAddress == null)
            {
                return;
            }
            elseif (filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
            {
                $this->climate->shout("That IP address is not valid.");
            }
        }

        $sth = $this->dbh->prepare("SELECT shortname FROM nas WHERE nasname ='$ipAddress'");
        $sth->execute();
        $i = 1;
        foreach ($sth->fetchAll() as $record)
        $name = ("{$record['shortname']}");

        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $characters[rand(0, strlen($characters) - 1)];
        }

        $sth = $this->dbh->prepare("SELECT coa, shortname FROM nas WHERE nasname ='$ipAddress'");
        $sth->execute();
        $i = 1;
        foreach ($sth->fetchAll() as $record)
        $name = ("{$record['shortname']}");
        $coa = ("{$record['coa']}");
        if ($coa == 0 ) {
            $this->climate->bold()->yellow("nas $name exists in the database and has coa disabled.") ;
            $sth = $this->dbh->prepare("UPDATE nas SET secret = '$secret' WHERE (nasname = '$ipAddress' AND coa = '0');");
            $sth->execute();
            $this->climate->bold()->magenta("updated the NAS with a new random secret of $secret - if you need to see this password in the future use the list nas function");
        } else {
            $this->climate->bold()->yellow("nas $name exists in the database and has coa enabled.") ;
            $this->climate->shout("Failed to change the NAS password");
            return;
        }

    try {
            CommandExecutor::executeCommand("/usr/sbin/service freeradius restart");
        }
        catch (RuntimeException $e)
        {
            $this->climate->shout("Failed to restart FreeRADIUS: {$e->getMessage()}");
        }

    }

    /**
    * change the password of a nas with a coa endpoint
    */
    public function changeNasPwCoa()
    {
        $input = $this->climate->lightBlue()->input("Enter NAS IP address $this->goBack");
        $ipAddress = null;
        while ($ipAddress == null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
        {
            $ipAddress = $input->prompt();
            if ($ipAddress == null)
            {
                return;
            }
            elseif (filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
            {
                $this->climate->shout("That IP address is not valid.");
            }
        }

        $sth = $this->dbh->prepare("SELECT shortname FROM nas WHERE nasname ='$ipAddress'");
        $sth->execute();
        $i = 1;
        foreach ($sth->fetchAll() as $record)
        $name = ("{$record['shortname']}");

        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $characters[rand(0, strlen($characters) - 1)];
        }

        $sth = $this->dbh->prepare("SELECT coa, shortname FROM nas WHERE nasname ='$ipAddress'");
        $sth->execute();
        $i = 1;
        foreach ($sth->fetchAll() as $record)
        $name = ("{$record['shortname']}");
        $coa = ("{$record['coa']}");
        if ($coa == 1 ) {
            $this->climate->bold()->yellow("nas $name exists in the database and has coa enabled.") ;
            $sth = $this->dbh->prepare("UPDATE nas SET secret = '$secret' WHERE (nasname = '$ipAddress' AND coa = '1');");
            $sth->execute();
            $this->climate->bold()->magenta("updated the NAS with a new random secret of $secret - if you need to see this password in the future use the list nas function");
            CommandExecutor::executeCommand("/bin/sed -i 's/secret = .*/secret = $secret/g' /etc/freeradius/sites-config/coa-relay/homeservers/$name.conf");
        } else {
            $this->climate->bold()->yellow("nas $name exists in the database and has coa disabled.") ;
            $this->climate->shout("Failed to change the NAS password");
            return;
        }

    try {
            CommandExecutor::executeCommand("/usr/sbin/service freeradius restart");
        }
        catch (RuntimeException $e)
        {
            $this->climate->shout("Failed to restart FreeRADIUS: {$e->getMessage()}");
        }

    }

    /**
     * Delete a NAS without a coa endpoint
     */
    public function deleteNas()
    {
        $input = $this->climate->lightBlue()->input("Enter IP address of the NAS you want to remove $this->goBack");
        $ipAddress = null;
        while ($ipAddress == null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
        {
            $ipAddress = $input->prompt();
            if ($ipAddress == null)
            {
                return;
            }
            elseif (filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
            {
                $this->climate->shout("That IP address is not valid.");
            }
        }

        $sth = $this->dbh->prepare("SELECT nasname FROM nas WHERE nasname='$ipAddress'");
        $sth->execute();
        $result = $sth->fetch(PDO::FETCH_ASSOC);
        if ($result == false)
        {
            $this->climate->shout("There is no NAS with the IP $ipAddress. Please check the ip address, using the list nas function.");
            return;
        }
        $sth = $this->dbh->prepare("SELECT shortname, coa FROM nas WHERE nasname ='$ipAddress'");
        $sth->execute();
        $i = 1;
        foreach ($sth->fetchAll() as $record)
        {
            $this->climate->bold()->yellow("nas {$record['shortname']}");
            $i++;
        }
        $name = ("{$record['shortname']}");
        $coa = ("{$record['coa']}");
        if ($coa == 1)
        {
            $this->climate->bold()->red("the nas {$record['shortname']} currently has a coa status of {$record['coa']} ");
            $this->climate->bold()->red("Failed to delete the NAS. the nas is configured with coa! ");
            $this->climate->bold()->red("use the List NAS entries function for hints.");
        }
        elseif ($coa == 0)
        {
        $sth = $this->dbh->prepare("DELETE from nas WHERE nasname='$ipAddress' AND coa=FALSE");
        $sth->execute();

            $this->climate->bold()->red("the nas has been removed! ");
            try {
                CommandExecutor::executeCommand("/usr/sbin/service freeradius restart");
            }
            catch (RuntimeException $e)
            {
                $this->climate->shout("Failed to restart FreeRADIUS!");
            }
        }
        else
        {
            $this->climate->shout("Failed to delete the NAS from the database. Maybe the IP wasn't found? Try using the List NAS entries function first.");
        }
    }


    /*
     * Delete a NAS with a coa endpoint
     */
    public function deleteNasCoa()
    {
        $input = $this->climate->lightBlue()->input("Enter IP address of the NAS you want to remove $this->goBack");
        $ipAddress = null;
        while ($ipAddress == null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
        {
            $ipAddress = $input->prompt();
            if ($ipAddress == null)
            {
                return;
            }
            elseif (filter_var($ipAddress, FILTER_VALIDATE_IP) === false)
            {
                $this->climate->shout("That IP address is not valid.");
            }
        }

        $sth = $this->dbh->prepare("SELECT nasname FROM nas WHERE nasname='$ipAddress'");
        $sth->execute();
        $result = $sth->fetch(PDO::FETCH_ASSOC);
        if ($result == false)
        {
            $this->climate->shout("There is no NAS with the IP $ipAddress. Please check the ip address, using the list nas function.");
            return;
        }
        $sth = $this->dbh->prepare("SELECT shortname, coa FROM nas WHERE nasname ='$ipAddress'");
        $sth->execute();
        $i = 1;
        foreach ($sth->fetchAll() as $record)
        {
            $this->climate->bold()->yellow("nas {$record['shortname']}");
            $i++;
        }
        $name = ("{$record['shortname']}");
        $coa = ("{$record['coa']}");
        if ($coa == 0 )
        {
            $this->climate->bold()->red("the nas {$record['shortname']} currently has a coa status of {$record['coa']} ");
            $this->climate->bold()->red("Failed to delete the NAS. the nas is not configured with coa! ");
            $this->climate->bold()->red("Try using the List NAS entries function for hints.");
        }
        elseif ($coa == 1 )
        {
            $sth = $this->dbh->prepare("DELETE from nas WHERE nasname='$ipAddress' AND coa=TRUE");
            $sth->execute();
            $this->climate->bold()->red("the nas has been removed! ");
            try {
                CommandExecutor::executeCommand("/bin/rm /etc/freeradius/sites-config/coa-relay/homeservers/$name.conf");
                $sth = $this->dbh->prepare("SELECT GROUP_CONCAT(DISTINCT shortname ) FROM nas WHERE coa=TRUE;");
                $sth->execute();
                $result = $sth->fetch(PDO::FETCH_ASSOC);
                if ($result !== false)
                    {
                    $this->climate->shout("home_server = {$result['GROUP_CONCAT(DISTINCT shortname )']}");
                    $coaepl = ("localhost-coa,{$result['GROUP_CONCAT(DISTINCT shortname )']}");
                    CommandExecutor::executeCommand("/bin/sed -i 's/home_server = .*/home_server = $coaepl/g' /etc/freeradius/sites-config/coa-relay/pool.conf");
                }
                CommandExecutor::executeCommand("/usr/sbin/service freeradius restart");
                }
                catch (RuntimeException $e)
            {
                $this->climate->shout("Failed to restart FreeRADIUS!");
            }
        }
        else
        {
            $this->climate->shout("Failed to delete the NAS from the database. Maybe the IP wasn't found? Try using the List NAS entries function first.");
        }
    }
}
