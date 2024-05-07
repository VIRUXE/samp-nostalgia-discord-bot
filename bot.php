<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/BugsCommand.php';
require __DIR__ . '/Peasants.php';

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event;
use Peasants\Peasant;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$stickyMessageContent = null;
$moderationQueue      = [];

$discord = new Discord([
    'token'   => $_ENV['DISCORD_TOKEN'],
    'intents' => Intents::getDefaultIntents()
]);

$discord->on("ready", function (Discord $discord) use (&$stickyMessageContent) {
    echo "Bot is ready!", PHP_EOL;

    // Load sticky message from file
    $stickyMessageContent = file_get_contents('sticky');

    echo "Sticky message: $stickyMessageContent", PHP_EOL;
});

$discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) use (&$stickyMessageContent, &$moderationQueue) {
    if ($message->author->id == $discord->id) return; // If the message is from the bot, ignore it
    
    $content = explode(" ", trim($message->content));
    $command = $content[0];
    
    if (Peasant::notPeasant($message->author->id)) {
        switch($command) {
            case '!aliases': {
                if(count($content) <= 1) break;
            
                try {
                    $gameserver = new SQLite3('../servidor/scriptfiles/data/accounts.db', SQLITE3_OPEN_READWRITE);
            
                    $nick = $content[1];
                    
                    $aliasesQuery = "SELECT name, hash FROM gpci_log WHERE hash IN (SELECT hash FROM gpci_log WHERE name = :nick COLLATE NOCASE) AND name <> :nick COLLATE NOCASE;";
                    $stmt = $gameserver->prepare($aliasesQuery);
                    $stmt->bindValue(':nick', $nick);

                    $aliasesResult = $stmt->execute();
                    
                    if($aliasesResult) {
                        $aliases = [];
                        while ($row = $aliasesResult->fetchArray()) $aliases[] = $row['name'];
                    
                        if(count($aliases)) {
                            $aliasesList = implode(', ', $aliases);
                            $message->channel->sendMessage("`$nick` também conhecido por: `$aliasesList`");
                        } else
                            $message->channel->sendMessage("`$nick` não tem aliases.");
                    } else
                        $message->channel->sendMessage("`$nick` não encontrado no banco de dados.");
                } catch (Exception $e) {
                    $message->channel->sendMessage("Ocorreu um erro ao autorizar: " . $e->getMessage());
                } finally {
                    $gameserver->close();
                }
            
                break;
            }
            case '!ma': {
                if(count($content) <= 1) break;
            
                try {
                    $gameserver = new SQLite3('../servidor/scriptfiles/data/accounts.db', SQLITE3_OPEN_READWRITE);
            
                    $nick = $content[1];
                    
                    $checkQuery = "SELECT hash FROM gpci_log WHERE name = '$nick';";
                    $checkResult = $gameserver->query($checkQuery);
                    $row = $checkResult->fetchArray();
            
                    if ($row) {
                        $hash        = $row['hash'];
                        $countQuery  = "SELECT COUNT(*) as cnt FROM gpci_log WHERE hash = '$hash';";
                        $countResult = $gameserver->query($countQuery);
                        $countRow    = $countResult->fetchArray();
            
                        if ($countRow['cnt'] > 1) {
                            $insertQuery = "INSERT OR REPLACE INTO gpci_allowed (name, hash) VALUES ('$nick', '$hash');";
                            
                            $gameserver->exec($insertQuery) ?
                                $message->channel->sendMessage("`$nick` autorizado.") :
                                $message->channel->sendMessage("`$nick` já se encontra autorizado.");
                        } else {
                            $message->channel->sendMessage("`$nick` não precisa de autorização.");
                        }
                    } else {
                        $message->channel->sendMessage("`$nick` não encontrado no registro.");
                    }
                } catch (Exception $e) {
                    $message->channel->sendMessage("Ocorreu um erro ao autorizar: " . $e->getMessage());
                } finally {
                    $gameserver->close();
                }
            
                break;
            }
            case '!sticky':
                // Delete the previous sticky message
                $message->channel->getMessageHistory(['limit' => 100])
                    ->done(function ($messages) use ($stickyMessageContent) {
                        foreach ($messages as $msg) {
                            if ($msg->content == $stickyMessageContent) $msg->delete();
                        }
                    }
                );

                $stickyMessageContent = substr($message->content, 8);
                
                echo "Sticky message changed to: $stickyMessageContent", PHP_EOL;

                // Delete the command message
                $message->delete();
                
                // Send the new sticky message
                $message->channel->sendMessage($stickyMessageContent);

                // Write to file
                file_put_contents('sticky', $stickyMessageContent);
                
                break;
            /* case '!chave':
                if (count($content) > 1) {
                    $nick = $content[1];

                    $db = new mysqli(
                        $_ENV['DB_HOST'],
                        $_ENV['DB_USER'],
                        $_ENV['DB_PASSWORD'],
                        $_ENV['DB_NAME']
                    );
                
                    // Sanitize $nick
                    $nick   = $db->real_escape_string($nick);
                    $result = $db->query("SELECT code FROM otp WHERE nick = '$nick';");
                    
                    if ($result->num_rows) {
                        $code = $result->fetch_assoc()['code'];
                        $message->channel->sendMessage("A chave de acesso de `$nick` é `$code`");
                    } else
                        $message->channel->sendMessage("Não foi possível encontrar uma chave para `$nick`");
                    
                    $db->close();
                }
                break; */
            case '!approve':
                $message->delete();

                // Extract the index of the message to approve from the command
                $index = (int)($content[1] ?? 0);

                // Check if the index is valid
                if ($index >= 0 && $index < count($moderationQueue)) {
                    // Get the message to approve
                    $msgToApprove = $moderationQueue[$index];

                    // Send the approved message
                    $msgToApprove->channel->sendMessage("Partilha de {$msgToApprove->author}: `$msgToApprove->content`");
                    
                    // Remove the message from the moderation queue
                    unset($moderationQueue[$index]);

                    $msgToApprove->author->getPrivateChannel()->done(function ($channel) use ($msgToApprove) {
                        $channel->sendMessage("Sua mensagem `{$msgToApprove->content}` foi aprovada e publicada no bate-papo.");
                    });

                    $message->channel->sendMessage("Mensagem $index aprovada por {$message->author}");
                } else
                    $message->channel->sendMessage("{$message->author} não foi possível encontrar essa mensagem ($index)");
                
                break;
            default:
                break;
        }
    } else { // Non-admin shit
        if ($message->channel_id != $_ENV['MAIN_CHANNEL_ID']) return; // If the message is not from the main channel, ignore it

        if (Peasant::isSharing($message->content)) {
            // Sending the message to the staff channel for approval
            $staffChannel = $discord->getChannel($_ENV['STAFF_CHANNEL_ID']);
            if ($staffChannel !== null) {
                $index = count($moderationQueue);
                
                $staffChannel->sendMessage("Mensagem {$index} de {$message->author} esperando aprovação: `{$message->content}`");

                // Add the message to the moderation queue
                $moderationQueue[] = $message;

                $message->delete();
                
                // Send a DM to the user explaining that their message is pending approval
                $message->author->getPrivateChannel()->done(function ($channel) use ($message) {
                    $channel->sendMessage("Sua mensagem aguarda aprovação! `{$message->content}`.");
                });
            }
        } else if (Peasant::isAskingForIP($message->content)) {
            $serverIP = $_ENV['SERVER_ADDR']; // Replace with your actual server IP
            $message->channel->sendMessage("O IP do servidor é: `$serverIP`");
        } else if ($insult = Peasant::isSayingShit($message->content)) {
            $message->delete(); // Delete the message containing bad activity

            // Send the insult message mentioning the user
            $message->channel->sendMessage("{$message->author}, $insult");

            // Send a warning message to the staff channel with the user's name and content of the deleted message
            // Check if the bot has access to the staff channel
            $staffChannel = $discord->getChannel($_ENV['STAFF_CHANNEL_ID']);
            if ($staffChannel !== null) 
                $staffChannel->sendMessage("Usuário {$message->author} enviou uma mensagem inadequada: `{$message->content}`");
        } else if(Peasant::isAskingForSupport($message->content)) {
            $message->channel->sendMessage("O bate-papo não é para suporte. Utilize o canal <#1061051608022143016>");
        }
    }

    switch ($command) {
        case '!veh': {
            if(count($content) <= 1) break;

            $vehicleId = $content[1];
            $apiUrl = "http://sv.scavengenostalgia.fun/vehicle/{$vehicleId}";
        
            $json_data = file_get_contents($apiUrl);
            $vehicles = json_decode($json_data, true);
        
            $infoMsg = "Informação de Veículos:\n";
            
            if(is_numeric($vehicleId))
                foreach ($vehicles as $key => $value) $infoMsg .= "**" . ucfirst($key) . "**: " . ($value ?: 'Nada') . "\n";
            else {
                foreach ($vehicles as $modelId => $vehicleData) {
                    $infoMsg .= "**ID de Modelo**: " . $modelId . "\n";

                    if(is_array($vehicleData))
                        foreach ($vehicleData as $key => $value) $infoMsg .= "**" . ucfirst($key) . "**: " . ($value ?: 'Nada') . "\n";
                    else
                        $infoMsg .= "**Detalhes**: " . ($vehicleData ?: 'Nada') . "\n";

                    $infoMsg .= "\n";
                }
            }
        
            $message->channel->sendMessage($infoMsg);
            break;
        }
    }

    /* if (substr($message->content, 0, 6) == '!bugs') {
        if(!$bugs = BugsCommand::fetchBugs())
            $message->channel->sendMessage("Impossivel baixar a lista de bugs. 🪲");
        else {
            $output = BugsCommand::displayBugs($bugs);
            $message->channel->sendMessage($output);
        }
    } */

    if ($message->channel_id == $_ENV['MAIN_CHANNEL_ID'] && $message->content !== $stickyMessageContent) { // If the message is not the sticky message and not from the bot
        // Delete the previous sticky message
        $message->channel->getMessageHistory(['limit' => 100])
            ->done(function ($messages) use ($message, $stickyMessageContent) {
                foreach ($messages as $msg) { // This shit doesn't read bot messages
                    if ($msg->content == $stickyMessageContent) $msg->delete();
                }

                // Send the new sticky message after the latest non-sticky message
                $message->channel->sendMessage($stickyMessageContent);
            });
    }
});

$discord->run();
