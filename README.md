# Ark Websocket

## Usage

### Design your own class extending ArkWebSocketWorker and Daemon

You need to design three actions when

* a new connection comes
* a new message comes
* an existed connection closed

You need to do unmask, mask and broadcast yourself inside.

Then you have to write a daemon to run in CLI mode.
You can take `test` as an example.

### Set up your daemon

Your websocket server should listen on a port, 
and use a domain and path to be entrance for the frontend and the Load Balancer.

For example, your daemon, a file called `daemon.php` which runs `loop` method of `ArkWebSocketDaemon`, listens to 8000, your pages held by Nginx listens to 80,
your domain uses `web.socket.com`, and your path would be `wss://web.socket.com/wss-service`.

Your steps:

0. Deploy your site, use Nginx or so, listen on 80 or so. 
1. Run your daemon.php as `php -q daemon.php`, listen on 8000 or so; you may want to use `nohup` if you need.
2. Now config your SLB (80 or 443), when domain is `web.socket.com`
    1. while the path is `/wss-service`, send packages to 8000;
    2. otherwise, to 80;

Over.