<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Mail\Storage;

use Zend\Mail;
use Zend\Mail\Protocol;

class Imap extends AbstractStorage implements Folder\FolderInterface, Writable\WritableInterface
{
    // TODO: with an internal cache we could optimize this class, or create an extra class with
    // such optimizations. Especially the various fetch calls could be combined to one cache call

    /**
     * protocol handler
     * @var null|\Zend\Mail\Protocol\Imap
     */
    protected $protocol;

    /**
     * name of current folder
     * @var string
     */
    protected $currentFolder = '';

    /**
     * IMAP flags to constants translation
     * @var array
     */
    protected static $knownFlags = array('\Passed'   => Mail\Storage::FLAG_PASSED,
                                          '\Answered' => Mail\Storage::FLAG_ANSWERED,
                                          '\Seen'     => Mail\Storage::FLAG_SEEN,
                                          '\Deleted'  => Mail\Storage::FLAG_DELETED,
                                          '\Draft'    => Mail\Storage::FLAG_DRAFT,
                                          '\Flagged'  => Mail\Storage::FLAG_FLAGGED);

    /**
     * IMAP flags to search criteria
     * @var array
     */
    protected static $searchFlags = array('\Recent'   => 'RECENT',
                                           '\Answered' => 'ANSWERED',
                                           '\Seen'     => 'SEEN',
                                           '\Deleted'  => 'DELETED',
                                           '\Draft'    => 'DRAFT',
                                           '\Flagged'  => 'FLAGGED');

    /**
     * Count messages all messages in current box
     *
     * @param null $flags
     * @throws Exception\RuntimeException
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     * @return int number of messages
     */
    public function countMessages($flags = null)
    {
        if (!$this->currentFolder) {
            throw new Exception\RuntimeException('No selected folder to count');
        }

        if ($flags === null) {
            return count($this->protocol->search(array('ALL')));
        }

        $params = array();
        foreach ((array) $flags as $flag) {
            if (isset(static::$searchFlags[$flag])) {
                $params[] = static::$searchFlags[$flag];
            } else {
                $params[] = 'KEYWORD';
                $params[] = $this->protocol->escapeString($flag);
            }
        }
        return count($this->protocol->search($params));
    }

    /**
     * get a list of messages with number and size
     *
     * @param int $id number of message
     * @return int|array size of given message of list with all messages as array(num => size)
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     */
    public function getSize($id = 0)
    {
        if ($id) {
            return $this->protocol->fetch('RFC822.SIZE', $id);
        }
        return $this->protocol->fetch('RFC822.SIZE', 1, INF);
    }

    /**
     * Fetches message parts (only headers without content)
     *
     * @param $id
     * @return array
     */
    public function fetchParts($id, $partid = null)
    {
        $i = 1;
        $retval = array();
        $exists = true;
        $partid = is_null($partid) ? '' : "$partid.";
        while($i < 20 && $exists)
        {
            $headItem = "BODY[$partid$i.HEADER]";
            $mimeItem = "BODY[$partid$i.MIME]";
            $header = $this->protocol->fetch(array($headItem, $mimeItem), $id);
            if(strtoupper($header[$headItem])   != "NIL") $retval[] = $header[$headItem];
            elseif(strtoupper($header[$mimeItem]) != "NIL")
            {
                $retval[$i] = $header[$mimeItem];
            }
            else $exists = false;
            $i++;
        }
        return $retval;
    }

    /**
     * Fetch a message
     *
     * @param int|array $id number of message
     * @return \Zend\Mail\Storage\Message
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     */
    public function getMessage($id)
    {
        $data = $this->protocol->fetch(array('FLAGS', 'RFC822.HEADER', 'UID', 'BODYSTRUCTURE'), $id);

        if(!is_array($id)) $id = array($id);

        $retval = array();
        foreach($data as $single_id => $single_data)
        {
            $header = $single_data['RFC822.HEADER'];
            $uid    = $single_data['UID'];
            $bodystruct = $single_data['BODYSTRUCTURE'];
            $flags = array();
            foreach ($single_data['FLAGS'] as $flag) {
                $flags[] = isset(static::$knownFlags[$flag]) ? static::$knownFlags[$flag] : $flag;
            }

            $retval[$single_id] = new $this->messageClass(array('handler' => $this, 'id' => $single_id, 'headers' => $header, 'flags' => $flags, 'uid' => $uid, 'bodystructure' => $bodystruct));
        }
        return count($retval) == 1 ? $retval[0] : $retval;
    }

    /*
     * Get raw header of message or part
     *
     * @param  int               $id       number of message
     * @param  null|array|string $part     path to part or null for message header
     * @param  int               $topLines include this many lines with header (after an empty line)
     * @param  int $topLines include this many lines with header (after an empty line)
     * @return string raw header
     * @throws Exception\RuntimeException
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     */
    public function getRawHeader($id, $part = null, $topLines = 0)
    {
        if ($part !== null) {
            $part = intval($part);
            // TODO: implement
            //if($part > 0) return $this->protocol->fetch("RFC822.$part.HEADER", $id);
            throw new Exception\RuntimeException('not implemented');
        }

        // TODO: toplines
        return $this->protocol->fetch('RFC822.HEADER', $id);
    }

    /*
     * Returns chunk of binary data (attachment)
     *
     */
    public function downloadAttachment($id, $part, $file)
    {
        $this->protocol->setFile($file);
        $retval = $this->protocol->fetch("BODY[$part]", $id);
        $this->protocol->setFile(null);
        return $retval;
    }

    /*
     * Get raw content of message or part
     *
     * @param  int               $id   number of message
     * @param  null|array|string $part path to part or null for message content
     * @return string raw content
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     * @throws Exception\RuntimeException
     */
    public function getRawContent($id, $part = null, $mulitipart = true)
    {
        if ($part !== null) {
            return $mulitipart ? $this->protocol->fetch("BODY[$part.TEXT]", $id) : $this->protocol->fetch("BODY[$part]", $id);
        }

        return $this->protocol->fetch('RFC822.TEXT', $id);
    }

    /**
     * create instance with parameters
     * Supported parameters are
     *   - user username
     *   - host hostname or ip address of IMAP server [optional, default = 'localhost']
     *   - password password for user 'username' [optional, default = '']
     *   - port port for IMAP server [optional, default = 110]
     *   - ssl 'SSL' or 'TLS' for secure sockets
     *   - folder select this folder [optional, default = 'INBOX']
     *
     * @param  array $params mail reader specific parameters
     * @throws Exception\RuntimeException
     * @throws Exception\InvalidArgumentException
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     */
    public function __construct($params)
    {
        if (is_array($params)) {
            $params = (object) $params;
        }

        $this->has['flags'] = true;

        if ($params instanceof Protocol\Imap) {
            $this->protocol = $params;
            try {
                $this->selectFolder('INBOX');
            } catch (Exception\ExceptionInterface $e) {
                throw new Exception\RuntimeException('cannot select INBOX, is this a valid transport?', 0, $e);
            }
            return;
        }

        if (!isset($params->user)) {
            throw new Exception\InvalidArgumentException('need at least user in params');
        }

        $host     = isset($params->host)     ? $params->host     : 'localhost';
        $password = isset($params->password) ? $params->password : '';
        $port     = isset($params->port)     ? $params->port     : null;
        $ssl      = isset($params->ssl)      ? $params->ssl      : false;

        $this->protocol = new Protocol\Imap();
        $this->protocol->connect($host, $port, $ssl);
        if (!$this->protocol->login($params->user, $password)) {
            throw new Exception\RuntimeException('cannot login, user or password wrong');
        }
        $this->selectFolder(isset($params->folder) ? $params->folder : 'INBOX');
    }

    /**
     * Close resource for mail lib. If you need to control, when the resource
     * is closed. Otherwise the destructor would call this.
     */
    public function close()
    {
        $this->currentFolder = '';
        $this->protocol->logout();
    }

    /**
     * Keep the server busy.
     *
     * @throws Exception\RuntimeException
     */
    public function noop()
    {
        if (!$this->protocol->noop()) {
            throw new Exception\RuntimeException('could not do nothing');
        }
    }

    /**
     * Remove a message from server. If you're doing that from a web environment
     * you should be careful and use a uniqueid as parameter if possible to
     * identify the message.
     *
     * @param  int $id number of message
     * @throws Exception\RuntimeException
     */
    public function removeMessage($id)
    {
        if (!$this->protocol->store(array(Mail\Storage::FLAG_DELETED), $id, null, '+')) {
            throw new Exception\RuntimeException('cannot set deleted flag');
        }
        // TODO: expunge here or at close? we can handle an error here better and are more fail safe
        if (!$this->protocol->expunge()) {
            throw new Exception\RuntimeException('message marked as deleted, but could not expunge');
        }
    }

  /**
   * Get bodystructure of messages
   *
   * @param null $id
   * @return array|string
   */
  public function getBodystructure($id = null)
  {
    if ($id) {
      return $this->protocol->fetch('BODYSTRUCTURE', $id);
    }

    return $this->protocol->fetch('UID', 1, INF);
  }

  /**
     * get unique id for one or all messages
     *
     * if storage does not support unique ids it's the same as the message number
     *
     * @param int|null $id message number
     * @return array|string message number for given message or all messages as array
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     */
    public function getUniqueId($id = null)
    {
        if ($id) {
            return $this->protocol->fetch('UID', $id);
        }

        return $this->protocol->fetch('UID', 1, INF);
    }

    /**
     * get a message number from a unique id
     *
     * I.e. if you have a webmailer that supports deleting messages you should use unique ids
     * as parameter and use this method to translate it to message number right before calling removeMessage()
     *
     * @param string $id unique id
     * @throws Exception\InvalidArgumentException
     * @return int message number
     */
    public function getNumberByUniqueId($uid)
    {
        // TODO: use search to find number directly
        $id = $this->protocol->search(array('UID', $uid));

        return isset($id[0]) ? $id[0] : null;

        throw new Exception\InvalidArgumentException('unique id not found');
    }

    /**
     * @param $flag
     * @return bool|int|string
     * @throws Exception\InvalidArgumentException
     */
    public function getFlagedFolder($flag)
    {
      $folders = $this->protocol->listMailbox('');
      $retval = false;
      foreach ($folders as $globalName => $data)
      {
        $flags = $data['flags'];
        if(isset($flags[1]) && $flags[1] == $flag)
        {
          $retval = $globalName;
          break;
        }
      }
      return $retval;
    }


    /**
     * get root folder or given folder
     *
     * @param  string $rootFolder get folder structure for given folder, else root
     * @throws Exception\RuntimeException
     * @throws Exception\InvalidArgumentException
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     * @return \Zend\Mail\Storage\Folder root or wanted folder
     */
    public function getFolders($rootFolder = null)
    {
        $folders = $this->protocol->listMailbox((string) $rootFolder);
        if (!$folders) {
            throw new Exception\InvalidArgumentException('folder not found');
        }

        ksort($folders, SORT_STRING);
        $root = new Folder('/', '/', false);
        $stack = array(null);
        $folderStack = array(null);
        $parentFolder = $root;
        $parent = '';

        foreach ($folders as $globalName => $data) {
            do {
                if (!$parent || strpos($globalName, $parent) === 0) {
                    $pos = strrpos($globalName, $data['delim']);
                    if ($pos === false) {
                        $localName = $globalName;
                    } else {
                        $localName = substr($globalName, $pos + 1);
                    }
                    $selectable = !$data['flags'] || !in_array('\\Noselect', $data['flags']);

                    array_push($stack, $parent);
                    $parent = $globalName . $data['delim'];
                    $folder = new Folder($localName, $globalName, $selectable);
                    $parentFolder->$localName = $folder;
                    array_push($folderStack, $parentFolder);
                    $parentFolder = $folder;
                    break;
                } elseif ($stack) {
                    $parent = array_pop($stack);
                    $parentFolder = array_pop($folderStack);
                }
            } while ($stack);
            if (!$stack) {
                throw new Exception\RuntimeException('error while constructing folder tree');
            }
        }

        return $root;
    }

    /**
     * select given folder
     *
     * folder must be selectable!
     *
     * @param  \Zend\Mail\Storage\Folder|string $globalName global name of folder or instance for subfolder
     * @throws Exception\RuntimeException
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     */
    public function selectFolder($globalName)
    {
        $this->currentFolder = $globalName;
        $data = $this->protocol->select($this->currentFolder);
        if (!$data) {
            $this->currentFolder = '';
            throw new Exception\RuntimeException('cannot change folder, maybe it does not exist');
        }
        return $data;
    }

    /**
     * Examine given folder
     *
     * folder must be selectable!
     *
     * @param  \Zend\Mail\Storage\Folder|string $globalName global name of folder or instance for subfolder
     * @throws Exception\RuntimeException
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     */
    public function countUnseen($globalName)
    {
        $select = $this->protocol->select($globalName);
        $data   = $this->protocol->search(array('UNSEEN'));
        return count($data);
    }


    /**
     * get \Zend\Mail\Storage\Folder instance for current folder
     *
     * @return \Zend\Mail\Storage\Folder instance of current folder
     */
    public function getCurrentFolder()
    {
        return $this->currentFolder;
    }

    /**
     * create a new folder
     *
     * This method also creates parent folders if necessary. Some mail storages may restrict, which folder
     * may be used as parent or which chars may be used in the folder name
     *
     * @param  string                           $name         global name of folder, local name if $parentFolder is set
     * @param  string|\Zend\Mail\Storage\Folder $parentFolder parent folder for new folder, else root folder is parent
     * @throws Exception\RuntimeException
     */
    public function createFolder($name, $parentFolder = null)
    {
        // TODO: we assume / as the hierarchy delim - need to get that from the folder class!
        if ($parentFolder instanceof Folder) {
            $folder = $parentFolder->getGlobalName() . '/' . $name;
        } elseif ($parentFolder != null) {
            $folder = $parentFolder . '/' . $name;
        } else {
            $folder = $name;
        }

        if (!$this->protocol->create($folder)) {
            throw new Exception\RuntimeException('cannot create folder');
        }
    }

    /**
     * remove a folder
     *
     * @param  string|\Zend\Mail\Storage\Folder $name name or instance of folder
     * @throws Exception\RuntimeException
     */
    public function removeFolder($name)
    {
        if ($name instanceof Folder) {
            $name = $name->getGlobalName();
        }

        if (!$this->protocol->delete($name)) {
            throw new Exception\RuntimeException('cannot delete folder');
        }
    }

    /**
     * rename and/or move folder
     *
     * The new name has the same restrictions as in createFolder()
     *
     * @param  string|\Zend\Mail\Storage\Folder $oldName name or instance of folder
     * @param  string                           $newName new global name of folder
     * @throws Exception\RuntimeException
     */
    public function renameFolder($oldName, $newName)
    {
        if ($oldName instanceof Folder) {
            $oldName = $oldName->getGlobalName();
        }

        if (!$this->protocol->rename($oldName, $newName)) {
            throw new Exception\RuntimeException('cannot rename folder');
        }
    }

    /**
     * append a new message to mail storage
     *
     * @param  string                                $message message as string or instance of message class
     * @param  null|string|\Zend\Mail\Storage\Folder $folder  folder for new message, else current folder is taken
     * @param  null|array                            $flags   set flags for new message, else a default set is used
     * @throws Exception\RuntimeException
     */
     // not yet * @param string|\Zend\Mail\Message|\Zend\Mime\Message $message message as string or instance of message class
    public function appendMessage($message, $folder = null, $flags = null)
    {
        if ($folder === null) {
            $folder = $this->currentFolder;
        }

        if ($flags === null) {
            $flags = array(Mail\Storage::FLAG_SEEN);
        }

        // TODO: handle class instances for $message
        if (!$this->protocol->append($folder, $message, $flags)) {
            throw new Exception\RuntimeException('cannot create message, please check if the folder exists and your flags');
        }
    }

    /**
     * copy an existing message
     *
     * @param  int                              $id     number of message
     * @param  string|\Zend\Mail\Storage\Folder $folder name or instance of target folder
     * @throws Exception\RuntimeException
     */
    public function copyMessage($id, $folder)
    {
        if (!$this->protocol->copy($folder, $id)) {
            throw new Exception\RuntimeException('cannot copy message, does the folder exist?');
        }
    }

    /**
     * move an existing message
     *
     * NOTE: IMAP has no native move command, thus it's emulated with copy and delete
     *
     * @param  int                              $id     number of message
     * @param  string|\Zend\Mail\Storage\Folder $folder name or instance of target folder
     * @throws Exception\RuntimeException
     */
    public function moveMessage($id, $folder)
    {
        $this->copyMessage($id, $folder);
        $this->removeMessage($id);
    }

    /**
     * set flags for message
     *
     * NOTE: this method can't set the recent flag.
     *
     * @param  int   $id    number of message
     * @param  array $flags new flags for message
     * @throws Exception\RuntimeException
     */
    public function setFlags($id, $flags)
    {
        if (!$this->protocol->store($flags, $id)) {
            throw new Exception\RuntimeException('cannot set flags, have you tried to set the recent flag or special chars?');
        }
    }

    public function closeFolder()
    {
        $this->protocol->requestAndResponse('CLOSE');
        return;
    }

    /**
     * @param $sequenceId
     * @param $flag
     * @param $value
     * @throws Exception\RuntimeException
     */
    public function changeFlag($sequenceId, $flag, $value)
    {
        $mode = $value ? "+" : "-";
        if (!$this->protocol->store(array($flag), $sequenceId, null, $mode, true)) {
            throw new Exception\RuntimeException('cannot set flags, have you tried to set the recent flag or special chars?');
        }
    }
}
