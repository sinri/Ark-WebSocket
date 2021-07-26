<?php require __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="en">
<head>
    <title>Ark WebSocket Test Page</title>
    <script src="https://unpkg.com/vue@2.5.17/dist/vue.js"></script>
</head>
<body>
<div id="app">
    <h1>Ark WebSocket Test Page</h1>
    <div>
        <label>
            Connection: [{{websocketStatus}}]
            <button v-on:click="closeConnection">Close</button>
            <button v-on:click="openConnection">Open</button>
        </label>
        <label>
            Send Message:
            <input v-model="message">
            <button v-on:click="sendMessage">Send</button>
        </label>
    </div>
    <div>
        <div v-for="item in messageList">
            <p>[{{item.type}}] {{item.time}}</p>
            <pre>{{item.content}}</pre>
        </div>
    </div>
</div>
<script>
    const wsUri = '<?php echo $servicePath; ?>';
    let websocket = null;
    let vue = new Vue({
        el: '#app',
        data: {
            messageList: [],
            message: '',
            websocketStatus: '',
        },
        methods: {
            pushMessage: function (type, time, content) {
                this.messageList.push({type: type, time: time, content: content});
            },
            getMySQLFormatDateTimeExpression: function () {
                let now = new Date();
                return new Date(now.getTime() - now.getTimezoneOffset() * 60 * 1000).toISOString().slice(0, 19).replace('T', ' ');
            },
            refreshWebSocketStatus: function () {
                if (websocket) {
                    this.websocketStatus = websocket.readyState;
                } else {
                    this.websocketStatus = null;
                }
                console.log('websocketStatus -> ', this.websocketStatus);
            },
            closeConnection: function () {
                if (websocket !== null) {
                    console.log('websocket close first ...');
                    websocket.close();
                    websocket = null;
                }
            },
            openConnection: function () {
                if (websocket !== null) {
                    alert('Please close first!')
                    return;
                }

                let that = this;

                websocket = new WebSocket(wsUri);

                websocket.onopen = function (ev) {
                    // connection is open
                    console.log('onopen', ev);
                    that.pushMessage('system', that.getMySQLFormatDateTimeExpression(), 'Connected to websocket server');
                    that.refreshWebSocketStatus();
                };

                //#### Message received from server?
                websocket.onmessage = function (ev) {
                    //const msg = JSON.parse(ev.data); //PHP sends Json data
                    that.pushMessage('message', that.getMySQLFormatDateTimeExpression(), 'Message Comes: ' + ev.data);
                    that.refreshWebSocketStatus();
                };

                websocket.onerror = function (ev) {
                    that.pushMessage('error', that.getMySQLFormatDateTimeExpression(), 'Error Occurred: ' + ev.data);
                    that.refreshWebSocketStatus();
                };

                websocket.onclose = function (ev) {
                    console.log('onclose', ev);
                    that.pushMessage('system', that.getMySQLFormatDateTimeExpression(), 'Disconnected to websocket server. ');
                    that.refreshWebSocketStatus();
                    websocket = null;
                };
            },
            sendMessage: function () {
                websocket.send(this.message);
                this.message = '';
            }
        },
        mounted: function () {
            this.openConnection();
        }
    });
</script>
</body>
</html>