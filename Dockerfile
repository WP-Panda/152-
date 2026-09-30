FROM node:22-alpine
ENV NODE_ENV=production HOST=0.0.0.0 PORT=3000 DATA_DIR=/var/lib/wp-panda
WORKDIR /app
COPY package.json server.js ./
COPY public ./public
RUN mkdir -p /var/lib/wp-panda/packages && chown -R node:node /var/lib/wp-panda
USER node
EXPOSE 3000
VOLUME ["/var/lib/wp-panda"]
CMD ["node", "server.js"]
