<?php
use Parser\Parser;

class Client
{
    public $server = null;
    public $con = null;
    public $encoder = null;
    public $decode = null;
    public $id = null;
    public $request = null;
    public $nsps = array();
    public $connectBuffer = array();
    public function __construct($server, $conn)
    {
        $this->server = $server;
        $this->conn = $conn;
        $this->encoder = new \Parser\Encoder();
        $this->decoder = new \Parser\Decoder();
        $this->id = $conn->id;
        $this->request = $conn->request;
        $this->setup();
    }

/**
 * Sets up event listeners.
 *
 * @api private
 */

    public function setup(){
        /*$this->onclose = this.onclose.bind(this);
          this.ondata = this.ondata.bind(this);
          this.onerror = this.onerror.bind(this);
          this.ondecoded = this.ondecoded.bind(this);
         */
         $this->decoder->on('decoded', array($this,'ondecoded'));
         $this->conn->on('data', array($this,'ondata'));
         $this->conn->on('error', array($this, 'onerror'));
         $this->conn->on('close' ,array($this, 'onclose'));
    }

/**
 * Connects a client to a namespace.
 *
 * @param {String} namespace name
 * @api private
 */

    public function connect($name){
        if (!isset($this->server->nsps[$name])) 
        {
            $this->packet(array('type'=> Parser::ERROR, 'nsp'=> $name, 'data'=> 'Invalid namespace'));
            return;
        }
        $nsp = $this->server->of($name);
        if ('/' !== $name && !isset($this->nsps['/'])) 
        {
            $this->connectBuffer[$name] = $name;
            return;
        }
        $self = $this;
        $socket = $nsp->add($this, function($socket)use($nsp, $self){
            $self->sockets[] = $socket;
            $self->nsps[$nsp->name] = $socket;
            if ('/' === $nsp->name && $self->connectBuffer) 
            {
                foreach($self->connectBuffer as $name)
                {
                    $self->connect($name);
                }
                $self->connectBuffer = array();
            }
       });
    }

/**
 * Disconnects from all namespaces and closes transport.
 *
 * @api private
 */

    public function disconnect()
    {
        foreach($this->sockets as $socket)
        {
            $socket->disconnect();
        }
        $this->sockets = array();
        $this->close();
    }

/**
 * Removes a socket. Called by each `Socket`.
 *
 * @api private
 */

    public function remove($socket)
    {
        if(isset($this->sockets[$socket->id]))
        {
            $nsp = $this->sockets[$socket->id]->nsp->name;
            unset($this->sockets[$socket->id]);
            unset($this->nsps[$nsp]);
        } else {
            echo('ignoring remove for '. $socket->id);
        }
    }

/**
 * Closes the underlying connection.
 *
 * @api private
 */

    public function close()
    {
        if('open' === $this->conn->readyState) 
        {
             echo('forcing transport close');
             $this->conn->close();
             $this->onclose('forced server close');
        }
    }

/**
 * Writes a packet to the transport.
 *
 * @param {Object} packet object
 * @param {Object} options
 * @api private
 */
    public function packet($packet, $preEncoded = false, $volatile = false)
    {
        $self = $this;
        if('open' === $this->conn->readyState) 
        {
            if (!$preEncoded) 
            {
                // not broadcasting, need to encode
                $this->encoder->encode($packet, function ($encodedPackets)use($self, $volatile) { // encode, then write results to engine
                    $self->writeToEngine($encodedPackets, $volatile);
                });
            } else { // a broadcast pre-encodes a packet
                 $self->writeToEngine($packet);
            }
        } else {
             echo('ignoring packet write ' . $packet);
        }
    }

    public function  writeToEngine($encodedPackets, $volatile = false) 
    {
        if($volatile)echo new \Exception('volatile');
        if ($volatile && !$this->conn->transport->writable) return;
        // todo check
        if(isset($encodedPackets['nsp']))unset($encodedPackets['nsp']);
        foreach($encodedPackets as $packet) 
        {
             $this->conn->write($packet);
        }
    }


/**
 * Called with incoming transport data.
 *
 * @api private
 */

    public function ondata($data)
    {
        try {
            // todo chek '2["chat message","2"]' . "\0" . '' 
            $this->decoder->add(trim($data));
        } catch(\Exception $e) {
            $this->onerror($e);
        }
    }

/**
 * Called when parser fully decodes a packet.
 *
 * @api private
 */

    public function ondecoded($packet) 
    {
        if(Parser::CONNECT === $packet['type'])
        {
            $this->connect($packet->nsp);
        } else {
            $socket = $this->nsps[$packet['nsp']];
            if ($socket) 
            {
                 $socket->onpacket($packet);
            } else {
                echo('no socket for namespace ' . $packet['nsp']);
            }
        }
    }

/**
 * Handles an error.
 *
 * @param {Objcet} error object
 * @api private
 */

    public function onerror($err)
    {
        foreach($this->sockets as $socket)
        {
            $socket->onerror($err);
        }
        $this->onclose('client error');
    }

/**
 * Called upon transport close.
 *
 * @param {String} reason
 * @api private
 */

    public function onclose($reason)
    {

        // ignore a potential subsequent `close` event
        $this->destroy();

        // `nsps` and `sockets` are cleaned up seamlessly
        foreach($this->sockets as $socket) 
        {
            $socket->onclose($reason);
        }
        $this->sockets = null;
        $this->decoder->destroy(); // clean up decoder
    }

/**
 * Cleans up event listeners.
 *
 * @api private
 */

    public function destroy()
    {
        $this->conn->onData = null;
        $this->conn->onError = null;
        $this->conn->onClose = null;
        $this->decoder->removeListener('decoded', array($this, 'ondecoded'));
    }
}
