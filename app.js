console.log('Start app.js');

//import GeoTIFF, { fromUrl, fromUrls, fromArrayBuffer, fromBlob } from 'geotiff';

// var Tiff = require('tiff.js');
// var fs = require('fs');

// var filename = 'maps_tiff/Typ1_ak1223.tiff';
// var input = fs.readFileSync(filename);
// Tiff.initialize({ TOTAL_MEMORY: 19777216 * 10 });
// var image = new Tiff({ buffer: input });
// console.log(filename + ': width = ' + image.width() + ', height = ' + image.height());

const express = require('express');
const path = require('path');

const app = express();
const port = 3000;

app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname, 'index.html'));
});

app.listen(port, () => {
  console.log(`Server is running on port ${port}`);
});