const axios = require('axios');

axios.get('https://ptero.deepstak.uk/api/application/servers')
.then(function (response) {
  console.log(response.data);
})
.catch(function (error) {
  console.log(error);
});

