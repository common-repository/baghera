let baghera_api_connection_button = document.getElementById('baghera-api-connection-button');

if (baghera_api_connection_button !== null) {

    document.getElementById('baghera-api-connection-button').addEventListener('click', checkBagheraApiConnection);

    function checkBagheraApiConnection() {
        fetch('https://apikey-dot-baghere-suite.ew.r.appspot.com/api_fattura', { method: 'GET', headers: { 'Accept': 'application/json', 'Api-Key': scriptParams['api_key'] } }).
        then(response => {
            if (!response.ok) {
                document.getElementById('baghera-api-connection-output').innerHTML = "Connessione non stabilita";
                document.getElementById('baghera-api-connection-output').classList.add("alert", "alert-danger");
                throw new Error('Network response was not OK');
            }
            return response.json()
        }).
        then(data => {
            document.getElementById('baghera-api-connection-output').innerHTML = "Connessione OK!";
            document.getElementById('baghera-api-connection-output').classList.add("alert", "alert-success");

        }).catch(error => {
            console.error('There has been a problem with your fetch operation:', error);
            document.getElementById('baghera-api-connection-output').innerHTML = "Api Key non validata";
            document.getElementById('baghera-api-connection-output').classList.add("alert", "alert-danger");
        });

    }
}